"""
daemon.py
─────────────────────────────────────────────────────────────
Broker bridge: Web → Home Assistant (Empresa v3.1)

Flujo:
  1. Suscribe a  empresa/{house_id}/estado
       - Publica MQTT Discovery en  homeassistant/lock/{house_id}_{lock_id}/config
       - Publica estado actual en   empresa/{house_id}/{lock_id}/state
       - Registra log en BD SQLite

  2. Suscribe a  empresa/limpiar/{old_house_id}
       - Elimina MQTT Discovery (retain vacío)
       - Limpia retained en los state/set topics

  3. Suscribe a  empresa/{house_id}/{lock_id}/set
       - Comandos desde HA → reenvía estado y log

Formato esperado en empresa/{house_id}/estado:
  {
    "house_id": "uuid",
    "locks": [ { "id": "door-1", "state": "locked"|"unlocked" } ],
    "usuario": "admin"
  }

Formato en empresa/limpiar/{old_house_id}:
  {
    "house_id": "old-uuid",
    "locks": ["door-1", "door-2", ...]
  }
─────────────────────────────────────────────────────────────
"""

import json
import logging
import os
import sqlite3
import threading
import time
from datetime import datetime
from typing import Dict, Set

import paho.mqtt.client as mqtt

# ── Configuración ──────────────────────────────────────────────
BROKER  = os.getenv("MQTT_BROKER", "mosquitto")
PORT    = int(os.getenv("MQTT_PORT", 1883))
DB_PATH = os.getenv("DB_PATH", "/var/lib/smarthome/smarthome.db")
HA_DISCOVERY_PREFIX = "homeassistant"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [daemon] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)

cerraduras_por_plano: Dict[str, Set[str]] = {}
lock_db = threading.Lock()


# ── Base de datos ──────────────────────────────────────────────
def _init_db() -> None:
    db_dir = os.path.dirname(DB_PATH)
    if db_dir and not os.path.exists(db_dir):
        try:
            os.makedirs(db_dir, exist_ok=True)
        except OSError:
            pass
    try:
        with sqlite3.connect(DB_PATH, timeout=10) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.execute("""
                CREATE TABLE IF NOT EXISTS Logs (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    house_id TEXT    NOT NULL,
                    lock_id  TEXT    NOT NULL,
                    accion   TEXT    NOT NULL,
                    usuario  TEXT    DEFAULT 'sistema',
                    ts       DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            """)
            conn.commit()
        log.info(f"BD SQLite lista en {DB_PATH}")
    except Exception as e:
        log.warning(f"No se pudo inicializar BD: {e}")


def _registrar_log(house_id: str, lock_id: str, accion: str, usuario: str = "sistema") -> None:
    try:
        with lock_db:
            with sqlite3.connect(DB_PATH, timeout=5) as conn:
                conn.execute(
                    "INSERT INTO Logs (house_id, lock_id, accion, usuario) VALUES (?, ?, ?, ?)",
                    (house_id, lock_id, accion, usuario),
                )
                conn.commit()
    except Exception as e:
        log.debug(f"Log BD falló (no crítico): {e}")


# ── MQTT Discovery ─────────────────────────────────────────────
def _unique_id(house_id: str, lock_id: str) -> str:
    return f"cerraduras_{house_id[:8]}_{lock_id}".replace("-", "_")


def _publicar_discovery(client: mqtt.Client, house_id: str, lock_id: str, nombre_lock: str = None) -> None:
    uid    = _unique_id(house_id, lock_id)
    nombre = nombre_lock or f"Cerradura {lock_id} [{house_id[:8]}]"

    config_payload = {
        "name":           nombre,
        "unique_id":      uid,
        "command_topic":  f"empresa/{house_id}/{lock_id}/set",
        "state_topic":    f"empresa/{house_id}/{lock_id}/state",
        "payload_lock":   "LOCK",
        "payload_unlock": "UNLOCK",
        "state_locked":   "locked",
        "state_unlocked": "unlocked",
        "optimistic":     False,
        "retain":         True,
        "device": {
            "identifiers": [f"cerraduras_empresa_{house_id[:8]}"],
            "name":         f"Plano Empresa [{house_id[:8]}]",
            "model":        "CerradurasIoT v3.1",
            "manufacturer": "TFG IoT Empresa",
        },
    }

    client.publish(
        f"{HA_DISCOVERY_PREFIX}/lock/{uid}/config",
        json.dumps(config_payload),
        qos=1,
        retain=True,
    )
    log.info(f"  ↑ Discovery: {uid}")


def _limpiar_discovery(client: mqtt.Client, house_id: str, lock_ids) -> None:
    if not lock_ids:
        lock_ids = list(cerraduras_por_plano.get(house_id, []))

    log.info(f"Limpiando Discovery plano {house_id[:8]} — {len(lock_ids)} cerraduras")

    for lock_id in lock_ids:
        uid = _unique_id(house_id, lock_id)
        client.publish(
            f"{HA_DISCOVERY_PREFIX}/lock/{uid}/config",
            "",
            qos=1,
            retain=True,
        )
        client.publish(
            f"empresa/{house_id}/{lock_id}/state",
            "",
            qos=1,
            retain=True,
        )
        log.info(f"  ↓ Borrado: {uid}")

    cerraduras_por_plano.pop(house_id, None)


def _traducir_estado_ha(estado_web: str) -> str:
    return "locked" if estado_web.lower() in ("locked", "closed", "lock") else "unlocked"


# ── Callbacks MQTT ─────────────────────────────────────────────
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        log.info(f"Conectado a Mosquitto ({BROKER}:{PORT})")
        client.subscribe("empresa/+/estado",  qos=1)
        client.subscribe("empresa/limpiar/+", qos=1)
        client.subscribe("empresa/+/+/set",   qos=1)
        log.info("Suscrito a: empresa/+/estado | empresa/limpiar/+ | empresa/+/+/set")
    else:
        log.error(f"Fallo conexión MQTT rc={rc}")


def on_disconnect(client, userdata, rc):
    if rc != 0:
        log.warning(f"Desconectado inesperadamente (rc={rc}). Reconectando…")


def on_message(client, userdata, msg):
    topic   = msg.topic
    payload = msg.payload.decode("utf-8", errors="replace").strip()

    if not payload:
        return

    log.debug(f"← {topic}: {payload[:150]}")
    parts = topic.split("/")

    if len(parts) == 3 and parts[0] == "empresa" and parts[2] == "estado":
        _procesar_estado(client, parts[1], payload)
        return

    if len(parts) == 3 and parts[0] == "empresa" and parts[1] == "limpiar":
        _procesar_limpieza(client, parts[2], payload)
        return

    if len(parts) == 4 and parts[0] == "empresa" and parts[3] == "set":
        _procesar_comando_ha(client, parts[1], parts[2], payload)
        return


def _procesar_estado(client: mqtt.Client, house_id: str, payload: str) -> None:
    try:
        data = json.loads(payload)
    except json.JSONDecodeError:
        log.warning(f"JSON inválido en estado: {payload[:80]}")
        return

    locks   = data.get("locks", [])
    usuario = data.get("usuario", "sistema")

    if not isinstance(locks, list):
        return

    if house_id not in cerraduras_por_plano:
        cerraduras_por_plano[house_id] = set()

    for lock in locks:
        lock_id = lock.get("id")
        estado  = lock.get("state", "locked")

        if not lock_id:
            continue

        if lock_id not in cerraduras_por_plano[house_id]:
            _publicar_discovery(client, house_id, lock_id)
            cerraduras_por_plano[house_id].add(lock_id)

        estado_ha = _traducir_estado_ha(estado)
        client.publish(
            f"empresa/{house_id}/{lock_id}/state",
            estado_ha,
            qos=1,
            retain=True,
        )

        accion = "CERRAR" if estado_ha == "locked" else "ABRIR"
        log.info(f"  Estado: empresa/{house_id[:8]}/{lock_id} = {estado_ha} ({usuario})")
        _registrar_log(house_id, lock_id, accion, usuario)

        client.publish(
            "empresa/logs",
            json.dumps({
                "house_id": house_id,
                "lock_id":  lock_id,
                "accion":   accion,
                "usuario":  usuario,
                "ts":       datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }),
            qos=0,
            retain=False,
        )


def _procesar_limpieza(client: mqtt.Client, old_house_id: str, payload: str) -> None:
    try:
        data  = json.loads(payload)
        locks = data.get("locks", [])
    except json.JSONDecodeError:
        locks = []

    log.info(f"Limpieza solicitada para plano {old_house_id[:8]}")
    _limpiar_discovery(client, old_house_id, locks)
    _registrar_log(old_house_id, "*", "PLANO_ELIMINADO", "sistema")


def _procesar_comando_ha(client: mqtt.Client, house_id: str, lock_id: str, payload: str) -> None:
    comando   = payload.strip().upper()
    estado_ha = "locked" if comando == "LOCK" else "unlocked"

    client.publish(
        f"empresa/{house_id}/{lock_id}/state",
        estado_ha,
        qos=1,
        retain=True,
    )

    accion = "CERRAR" if estado_ha == "locked" else "ABRIR"
    log.info(f"  Cmd HA: empresa/{house_id[:8]}/{lock_id} → {estado_ha}")
    _registrar_log(house_id, lock_id, f"{accion}_HA", "home_assistant")

    client.publish(
        "empresa/logs",
        json.dumps({
            "house_id": house_id,
            "lock_id":  lock_id,
            "accion":   f"{accion}_HA",
            "usuario":  "home_assistant",
            "ts":       datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }),
        qos=0,
        retain=False,
    )


# ── Punto de entrada ───────────────────────────────────────────
def main():
    _init_db()

    client = mqtt.Client(
        client_id="cerraduras-daemon-empresa",
        protocol=mqtt.MQTTv311,
        clean_session=True,
    )
    client.on_connect    = on_connect
    client.on_disconnect = on_disconnect
    client.on_message    = on_message

    log.info(f"Conectando a Mosquitto ({BROKER}:{PORT})…")
    for intento in range(20):
        try:
            client.connect(BROKER, PORT, keepalive=60)
            break
        except Exception as e:
            log.warning(f"Intento {intento+1}/20 – Mosquitto no disponible: {e}")
            time.sleep(3)
    else:
        log.error("No se pudo conectar a Mosquitto. Abortando.")
        return

    log.info("Daemon CerradurasIoT Empresa v3.1 en marcha.")
    client.loop_forever()


if __name__ == "__main__":
    main()

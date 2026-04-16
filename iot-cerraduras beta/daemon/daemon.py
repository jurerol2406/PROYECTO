import json
import paho.mqtt.client as mqtt

BROKER = "mosquitto"

def on_connect(client, userdata, flags, rc):
    print("Conectado a MQTT")
    client.subscribe("cerraduras/estado")

def on_message(client, userdata, msg):
    payload = json.loads(msg.payload.decode())
    
    for lock in payload.get("locks", []):
        lock_id = lock["id"]

        # 🔥 MQTT DISCOVERY
        config_topic = f"homeassistant/lock/{lock_id}/config"

        config_payload = {
            "name": f"Cerradura {lock_id}",
            "command_topic": f"home/{lock_id}/set",
            "state_topic": f"home/{lock_id}/state",
            "payload_lock": "LOCK",
            "payload_unlock": "UNLOCK",
            "unique_id": lock_id
        }

        client.publish(config_topic, json.dumps(config_payload), retain=True)

        # Estado actual
        state_topic = f"home/{lock_id}/state"
        client.publish(state_topic, lock["state"], retain=True)

client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message

client.connect(BROKER, 1883, 60)
client.loop_forever()

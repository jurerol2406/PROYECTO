<div align="center">

# Cerraduras Virtuales Inteligentes

Proyecto intermodular ASIR orientado a la gestión de accesos inteligentes mediante tecnologías IoT, visión artificial y arquitectura basada en microservicios.

</div>

---

## Integrantes

- Adrián Alonso
- Ismael Quesada
- Tristán Tomás Borja
- Antonio Villaraso
- Juan Bautista Ureña Roldán

---

## Descripción

La plataforma integra múltiples tecnologías para construir un entorno domótico centralizado y escalable:

- Análisis automático de planos mediante FastAPI y OpenCV
- Generación dinámica de espacios interactivos
- Gestión de cerraduras virtuales en tiempo real
- Comunicación IoT mediante MQTT
- Integración automática con Home Assistant
- Sistema de usuarios, roles y permisos
- Arquitectura completamente desplegada con Docker Compose

El objetivo principal del proyecto es demostrar la integración real entre servicios distribuidos, automatización y control domótico empresarial.

---

## Arquitectura del sistema

```text
Usuario
   │
   ▼
Frontend Web (PHP + JS)
   │
   ▼
FastAPI + OpenCV
   │
   ▼
JSON estructurado
   │
   ▼
MQTT (Mosquitto)
   │
   ├── Home Assistant
   └── Daemon MQTT Discovery
```

---

## Tecnologías utilizadas

### Backend

- Python 3
- FastAPI
- OpenCV
- Uvicorn

### Frontend

- PHP
- JavaScript
- HTML/CSS
- MQTT.js

### IoT y Domótica

- Mosquitto MQTT
- Home Assistant
- MQTT Discovery

### Infraestructura

- Docker
- Docker Compose
- SQLite

---

## Funcionalidades principales

### Procesamiento de planos

- Subida de imágenes
- Limpieza y filtrado de planos
- Detección de habitaciones y puertas
- Generación automática de estructura JSON

### Plataforma web

- Inicio de sesión y control de acceso
- Gestión de usuarios y permisos
- Renderizado dinámico de empresas
- Asignación interactiva de cerraduras
- Logs y auditoría

### Comunicación en tiempo real

- MQTT sobre WebSockets
- Sincronización instantánea
- Actualización de estados en vivo
- Gestión dinámica de dispositivos

### Integración domótica

- Creación automática de cerraduras en Home Assistant
- MQTT Discovery
- Control centralizado de estados
- Preparado para integración con hardware real

---

## Roles del sistema

| Rol | Descripción |
|------|-------------|
| **Administrador** | Gestión total de usuarios, permisos y planos |
| **Usuario** | Interacción con planos autorizados y control de cerraduras |
| **Viewer** | Acceso en modo solo lectura |

---

## Estructura del proyecto

```text
cerraduras-iot/
├── api/
├── daemon/
├── web/
├── mosquitto/
├── homeassistant/
└── docker-compose.yml
```

---

## Despliegue

### Requisitos

- Docker
- Docker Compose

### Inicio del sistema

```bash
docker compose up -d --build
```

---

## Acceso a servicios

| Servicio | URL |
|-----------|-----|
| Web | http://localhost:8080 |
| API | http://localhost:8000 |
| Home Assistant | http://localhost:8123 |

---

## Objetivos del proyecto

- Integrar tecnologías IoT modernas
- Automatizar la gestión de accesos
- Implementar comunicación distribuida en tiempo real
- Aplicar visión artificial a entornos domóticos
- Diseñar una arquitectura modular y escalable

---

## Futuras mejoras

- Integración con IA multimodal
- Soporte para cerraduras físicas mediante ESP32
- Autenticación avanzada
- HTTPS y hardening de seguridad
- Escalabilidad horizontal del broker MQTT

---

## Repositorio

Proyecto desarrollado como Trabajo Final de Grado del ciclo ASIR.

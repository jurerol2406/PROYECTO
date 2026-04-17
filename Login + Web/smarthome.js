// ==========================================
// VARIABLES GLOBALES
// ==========================================
let draggedLock = null;
let currentData = null; // Almacena el JSON actual para consultas de ubicación

// ==========================================
// INICIALIZACIÓN
// ==========================================

// >>> NUEVO: Configurar botones de inventario nada más cargar la página
document.addEventListener('DOMContentLoaded', () => {
    inicializarBotonesInventario();
});

// Escuchador del botón principal para generar el plano
document.getElementById('btn-generate').addEventListener('click', function() {
    const jsonRaw = document.getElementById('json-input').value;
    try {
        currentData = JSON.parse(jsonRaw);
        renderizarPlano(currentData);
    } catch (e) {
        alert("Error en el formato JSON. Revisa las comas y llaves.");
        console.error(e);
    }
});

// ==========================================
// FUNCIÓN PRINCIPAL: RENDERIZADO
// ==========================================
function renderizarPlano(data) {
    const container = document.getElementById('canvas-container');
    const countRooms = document.getElementById('count-rooms');
    const countDoors = document.getElementById('count-doors');
    const pool = document.getElementById('lock-pool');
    
    // >>> NUEVO: Limpiar todo el estado anterior al generar un nuevo plano
    container.innerHTML = ''; 
    pool.innerHTML = ''; 
    document.getElementById('lista-dispositivos').innerHTML = '';
    document.getElementById('panel-control-detallado').style.display = 'none';
    document.getElementById('count-assigned').innerText = '0';
    
    let totalDoors = 0;
    const svgNS = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(svgNS, "svg");
    svg.setAttribute("width", "100%");
    svg.setAttribute("height", "100%");
    svg.setAttribute("viewBox", "0 0 100 100"); 
    
    data.rooms.forEach(room => {
        // --- DIBUJAR HABITACIÓN ---
        const rect = document.createElementNS(svgNS, "rect");
        rect.setAttribute("x", room.x);
        rect.setAttribute("y", room.y);
        rect.setAttribute("width", room.width);
        rect.setAttribute("height", room.height);
        rect.setAttribute("class", "room-rect");
        rect.style.fill = "rgba(16, 185, 129, 0.05)";
        rect.style.stroke = "#10b981";
        rect.style.strokeWidth = "0.3";
        svg.appendChild(rect);

        const text = document.createElementNS(svgNS, "text");
        text.setAttribute("x", room.x + (room.width / 2));
        text.setAttribute("y", room.y + (room.height / 2));
        text.setAttribute("font-size", "2.5");
        text.setAttribute("fill", "#94a3b8");
        text.setAttribute("text-anchor", "middle");
        text.textContent = room.name;
        svg.appendChild(text);

        // --- DIBUJAR PUERTAS ---
        if (room.doors) {
            room.doors.forEach(door => {
                totalDoors++;
                
                // >>> NUEVO: Punto 1 - Generar un candado físico por cada puerta del JSON
                crearElementoCandado();

                let dx = room.x, dy = room.y;
                if(door.position === "right") { dx += room.width; dy += (room.height * (door.offset/100)); }
                if(door.position === "left") { dy += (room.height * (door.offset/100)); }
                if(door.position === "top") { dx += (room.width * (door.offset/100)); }
                if(door.position === "bottom") { dx += (room.width * (door.offset/100)); dy += room.height; }

                const doorIcon = document.createElementNS(svgNS, "circle");
                doorIcon.setAttribute("cx", dx);
                doorIcon.setAttribute("cy", dy);
                doorIcon.setAttribute("r", "1.8");
                doorIcon.setAttribute("id", door.id);
                doorIcon.setAttribute("class", "door-node");
                doorIcon.style.fill = "#334155"; // Gris (sin candado asignado)
                doorIcon.style.cursor = "pointer";

                svg.appendChild(doorIcon);
            });
        }
    });

    container.appendChild(svg);
    
    // Actualizar marcadores inferiores
    countRooms.innerText = data.rooms.length;
    countDoors.innerText = totalDoors;
    
    // >>> NUEVO: Inicializar contadores y habilitar interacciones
    actualizarContadorDisponibles();
    habilitarZonasSoltar();
    actualizarEstadoHeader(); // Sincroniza el header 0/X
}

// ==========================================
// GESTIÓN DE INVENTARIO (PUNTO 2)
// ==========================================

function crearElementoCandado() {
    const pool = document.getElementById('lock-pool');
    const nuevo = document.createElement('div');
    nuevo.className = 'lock-item';
    nuevo.draggable = true;
    nuevo.innerText = '🔒';
    nuevo.id = 'lock-gen-' + Math.random().toString(36).substr(2, 5);
    
    nuevo.addEventListener('dragstart', function(e) {
        draggedLock = this;
        this.style.opacity = "0.5";
    });
    nuevo.addEventListener('dragend', function() { this.style.opacity = "1"; });
    
    pool.appendChild(nuevo);
}

function inicializarBotonesInventario() {
    document.getElementById('btn-add-lock').addEventListener('click', () => {
        crearElementoCandado();
        actualizarContadorDisponibles();
        actualizarEstadoHeader();
    });
    document.getElementById('btn-remove-lock').addEventListener('click', () => {
        const pool = document.getElementById('lock-pool');
        if (pool.lastElementChild) {
            pool.removeChild(pool.lastElementChild);
            actualizarContadorDisponibles();
            actualizarEstadoHeader();
        }
    });
}

function actualizarContadorDisponibles() {
    const pool = document.getElementById('lock-pool');
    document.getElementById('count-available').innerText = pool.children.length;
}

// ==========================================
// GESTIÓN DEL HEADER (ESTADO EN TIEMPO REAL)
// ==========================================

function actualizarEstadoHeader() {
    const totalPuertas = document.getElementById('count-doors').innerText;
    const asignadas = document.getElementById('count-assigned').innerText;
    const headerStatus = document.getElementById('header-status');
    
    if (headerStatus) {
        headerStatus.innerText = `🟢 ${asignadas}/${totalPuertas} puertas protegidas`;
    }
}

// ==========================================
// DRAG & DROP Y PANEL DE CONTROL (PUNTO 3)
// ==========================================

function habilitarZonasSoltar() {
    const puertas = document.querySelectorAll('.door-node');
    const countAssigned = document.getElementById('count-assigned');

    puertas.forEach(puerta => {
        puerta.addEventListener('dragover', (e) => e.preventDefault());
        
        puerta.addEventListener('drop', function(e) {
            e.preventDefault();
            
            // Solo permitir si hay algo arrastrándose y la puerta no tiene ya un candado
            if (draggedLock && !this.dataset.assigned) {
                draggedLock.remove(); // Eliminar candado del almacén visual
                
                // Configurar el punto en el plano
                this.style.fill = "#ef4444"; // Activa el sistema en Rojo (Bloqueado)
                this.style.stroke = "gold";
                this.style.strokeWidth = "0.8";
                this.dataset.assigned = "true";
                
                // Buscar ubicación exacta para el panel de control
                const room = currentData.rooms.find(r => r.doors && r.doors.some(d => d.id === this.id));
                const doorData = room.doors.find(d => d.id === this.id);
                const trad = {left: "Izq.", right: "Der.", top: "Sup.", bottom: "Inf."};
                const ubicacion = `${room.name} (${trad[doorData.position]})`;
                
                // Crear fila en el panel de control lateral
                crearFilaControl(this.id, ubicacion);

                // Actualizar todos los contadores
                countAssigned.innerText = document.querySelectorAll('.device-row').length;
                actualizarContadorDisponibles();
                actualizarEstadoHeader(); // Sincroniza el header tras el drop
                
                draggedLock = null;
            }
        });
    });
}

function crearFilaControl(doorId, ubicacion) {
    const panel = document.getElementById('panel-control-detallado');
    const lista = document.getElementById('lista-dispositivos');
    panel.style.display = "block";

    const fila = document.createElement('div');
    fila.className = 'device-row';
    fila.id = `row-${doorId}`;
    // Estilo in-line para asegurar visibilidad, aunque se puede mover al CSS
    fila.style = "background:#1e293b; padding:10px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; border-left:4px solid #ef4444; margin-bottom: 8px;";

    fila.innerHTML = `
        <div style="display:flex; align-items:center; gap:10px;">
            <span id="icon-${doorId}" style="font-size:1.2rem;">🔒</span>
            <div>
                <div style="font-size:0.85rem; font-weight:bold; color:white;">${ubicacion}</div>
                <small id="status-${doorId}" style="color:#ef4444;">Estado: Cerrado</small>
            </div>
        </div>
        <button onclick="interactuar('${doorId}')" id="btn-${doorId}" style="background:#10b981; border:none; color:white; padding:5px 12px; border-radius:4px; cursor:pointer; font-size:0.8rem;">Abrir</button>
    `;
    lista.appendChild(fila);
}

// Lógica de interactividad para los botones del panel y el plano
window.interactuar = function(doorId) {
    const circulo = document.getElementById(doorId);
    const btn = document.getElementById(`btn-${doorId}`);
    const statusTxt = document.getElementById(`status-${doorId}`);
    const icon = document.getElementById(`icon-${doorId}`);
    const row = document.getElementById(`row-${doorId}`);

    // Si el círculo está en Rojo (Cerrado)
    if (circulo.style.fill === "rgb(239, 68, 68)" || circulo.style.fill === "#ef4444") { 
        circulo.style.fill = "#10b981"; // Cambiar a Verde (Abierto)
        btn.innerText = "Cerrar";
        btn.style.background = "#ef4444";
        statusTxt.innerText = "Estado: Abierto";
        statusTxt.style.color = "#10b981";
        icon.innerText = "🔓";
        row.style.borderLeftColor = "#10b981";
    } else {
        circulo.style.fill = "#ef4444"; // Cambiar a Rojo (Cerrado)
        btn.innerText = "Abrir";
        btn.style.background = "#10b981";
        statusTxt.innerText = "Estado: Cerrado";
        statusTxt.style.color = "#ef4444";
        icon.innerText = "🔒";
        row.style.borderLeftColor = "#ef4444";
    }
};
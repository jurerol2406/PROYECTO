document.getElementById('btn-generate').addEventListener('click', function() {
    const jsonRaw = document.getElementById('json-input').value;
    
    try {
        const data = JSON.parse(jsonRaw);
        renderizarPlano(data);
    } catch (e) {
        alert("Error en el formato JSON. Revisa las comas y llaves.");
        console.error(e);
    }
});

function renderizarPlano(data) {
    const container = document.getElementById('canvas-container');
    const countRooms = document.getElementById('count-rooms');
    const countDoors = document.getElementById('count-doors');
    
    container.innerHTML = ''; // Limpiar plano anterior
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

        // --- DIBUJAR PUERTAS (CANDADOS) ---
        if (room.doors) {
            room.doors.forEach(door => {
                totalDoors++;
                
                // Calcular posición de la puerta
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
                
                // ESTADO INICIAL: Bloqueado (Rojo)
                doorIcon.setAttribute("class", "door-node locked");
                doorIcon.style.fill = "#ef4444"; 
                doorIcon.style.cursor = "pointer";

                // --- LÓGICA DE CLIC (INTERACTIVIDAD) ---
                doorIcon.addEventListener('click', function() {
                    if (this.style.fill === "rgb(239, 68, 68)") { // Si es Rojo (#ef4444)
                        this.style.fill = "#10b981"; // Cambiar a Verde
                        console.log(`🔓 Abriendo puerta: ${this.id}`);
                    } else {
                        this.style.fill = "#ef4444"; // Cambiar a Rojo
                        console.log(`🔒 Cerrando puerta: ${this.id}`);
                    }
                });

                svg.appendChild(doorIcon);
            });
        }
    });

    container.appendChild(svg);
    countRooms.innerText = data.rooms.length;
    countDoors.innerText = totalDoors;

    ////////
    habilitarZonasSoltar();
}

// --- LÓGICA DE DRAG & DROP ---

let draggedLock = null;

// Configurar los candados del almacén
document.querySelectorAll('.lock-item').forEach(lock => {
    lock.addEventListener('dragstart', function(e) {
        draggedLock = this; // Guardamos el candado que estamos moviendo
        this.style.opacity = "0.5";
    });

    lock.addEventListener('dragend', function(e) {
        this.style.opacity = "1";
    });
});

// Esta función se llamará CADA VEZ que generes el plano
// para que los círculos nuevos también acepten candados
function habilitarZonasSoltar() {
    const puertas = document.querySelectorAll('.door-node');
    const countAssigned = document.getElementById('count-assigned');
    const countAvailable = document.getElementById('count-available');

    puertas.forEach(puerta => {
        // Permitir que el ratón "suelte" cosas aquí
        puerta.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.r = "2.5"; // Efecto visual al pasar por encima
        });

        puerta.addEventListener('dragleave', function() {
            this.style.r = "1.8"; // Volver al tamaño normal
        });

        puerta.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.r = "1.8";
            
            if (draggedLock) {
                // 1. "Consumir" el candado visualmente
                draggedLock.style.display = "none"; 
                
                // 2. Marcar la puerta como protegida
                this.style.stroke = "gold";
                this.style.strokeWidth = "0.8";
                this.dataset.assigned = "true";
                
                // 3. Actualizar contadores
                let asignados = parseInt(countAssigned.innerText);
                let disponibles = parseInt(countAvailable.innerText);
                
                countAssigned.innerText = asignados + 1;
                countAvailable.innerText = disponibles - 1;

                console.log(`Candado asignado a la puerta: ${this.id}`);
                draggedLock = null;
            }
        });
    });
}
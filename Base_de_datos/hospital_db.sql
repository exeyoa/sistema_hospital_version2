-- Base de datos: hospital_db
-- Sistema de consultas médicas - normalizado hasta 3FN
-- Para importar en un hosting: entrar a phpMyAdmin, seleccionar la base ya
-- creada y pegar el contenido; estas dos líneas se dejan comentadas:
-- CREATE DATABASE IF NOT EXISTS hospital_db CHARACTER SET utf8mb4;
-- USE hospital_db;

-- Catálogo de roles del sistema
CREATE TABLE roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre_rol VARCHAR(30) NOT NULL UNIQUE
);

-- Cuentas de acceso al sistema (médicos, recepcionistas, admin)
CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    apellido VARCHAR(60) NOT NULL,
    correo VARCHAR(100) UNIQUE,
    usuario VARCHAR(40) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    id_rol INT NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol)
);

-- Registro de intentos de inicio de sesión (control de fuerza bruta, RNF-08)
CREATE TABLE intentos_login (
    id_intento INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exitoso TINYINT(1) NOT NULL,
    ip VARCHAR(45),
    KEY idx_usuario_fecha (id_usuario, fecha_hora),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

-- Códigos de recuperación de contraseña (6 dígitos, expiran en 5 minutos)
CREATE TABLE codigos_recuperacion (
    id_codigo INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    codigo CHAR(6) NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    KEY id_usuario (id_usuario),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
);

-- Catálogo de especialidades médicas
CREATE TABLE especialidades (
    id_especialidad INT AUTO_INCREMENT PRIMARY KEY,
    nombre_especialidad VARCHAR(60) NOT NULL UNIQUE,
    descripcion TEXT
);

-- Datos propios de cada médico, ligado a su cuenta de usuario
CREATE TABLE medicos (
    id_medico INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    id_especialidad INT NOT NULL,
    numero_colegiado VARCHAR(30),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
    FOREIGN KEY (id_especialidad) REFERENCES especialidades(id_especialidad)
);

-- Pacientes (no necesitan cuenta de acceso al sistema)
CREATE TABLE pacientes (
    id_paciente INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    apellido VARCHAR(60) NOT NULL,
    cedula VARCHAR(20) NOT NULL UNIQUE,
    fecha_nacimiento DATE,
    sexo ENUM('M', 'F', 'Otro'),
    telefono VARCHAR(20),
    direccion VARCHAR(150),
    correo VARCHAR(100)
);

-- Citas agendadas con anticipación
CREATE TABLE citas (
    id_cita INT AUTO_INCREMENT PRIMARY KEY,
    id_paciente INT NOT NULL,
    id_medico INT NOT NULL,
    fecha_cita DATE NOT NULL,
    hora_cita TIME NOT NULL,
    estado ENUM('pendiente', 'confirmada', 'cancelada', 'atendida') DEFAULT 'pendiente',
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_paciente) REFERENCES pacientes(id_paciente),
    FOREIGN KEY (id_medico) REFERENCES medicos(id_medico)
);

-- Cola de atención del día (con cita o espontáneo)
CREATE TABLE turnos (
    id_turno INT AUTO_INCREMENT PRIMARY KEY,
    id_paciente INT NOT NULL,
    id_cita INT NULL,
    numero_turno VARCHAR(10) NOT NULL,
    tipo ENUM('con_cita', 'espontaneo') NOT NULL,
    estado ENUM('en_espera', 'en_consulta', 'atendido') DEFAULT 'en_espera',
    fecha DATE NOT NULL,
    FOREIGN KEY (id_paciente) REFERENCES pacientes(id_paciente),
    FOREIGN KEY (id_cita) REFERENCES citas(id_cita)
);

-- Registro clínico de cada consulta
CREATE TABLE consultas (
    id_consulta INT AUTO_INCREMENT PRIMARY KEY,
    id_turno INT NOT NULL,
    id_paciente INT NOT NULL,
    id_medico INT NOT NULL,
    fecha_consulta DATETIME DEFAULT CURRENT_TIMESTAMP,
    motivo VARCHAR(255),
    diagnostico TEXT,
    observaciones TEXT,
    FOREIGN KEY (id_turno) REFERENCES turnos(id_turno),
    FOREIGN KEY (id_paciente) REFERENCES pacientes(id_paciente),
    FOREIGN KEY (id_medico) REFERENCES medicos(id_medico)
);

-- Catálogo de medicamentos disponibles
CREATE TABLE medicamentos (
    id_medicamento INT AUTO_INCREMENT PRIMARY KEY,
    nombre_medicamento VARCHAR(100) NOT NULL,
    presentacion VARCHAR(60)
);

-- Una receta por consulta
CREATE TABLE recetas (
    id_receta INT AUTO_INCREMENT PRIMARY KEY,
    id_consulta INT NOT NULL UNIQUE,
    fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('activa','expirada') NOT NULL DEFAULT 'activa',
    indicaciones TEXT NULL,
    FOREIGN KEY (id_consulta) REFERENCES consultas(id_consulta)
);

-- Medicamentos incluidos en cada receta (una receta puede tener varios)
CREATE TABLE receta_detalle (
    id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_receta INT NOT NULL,
    id_medicamento INT NOT NULL,
    dosis VARCHAR(60) NOT NULL,
    frecuencia VARCHAR(60) NOT NULL,
    duracion VARCHAR(60) NOT NULL,
    FOREIGN KEY (id_receta) REFERENCES recetas(id_receta),
    FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento)
);

-- Datos iniciales de catálogos
INSERT INTO roles (nombre_rol) VALUES ('admin'), ('medico'), ('recepcionista');

INSERT INTO especialidades (nombre_especialidad) VALUES
('Medicina general'), ('Pediatría'), ('Ginecología');

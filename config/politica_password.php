<?php
/**
 * Política de contraseñas del sistema hospitalario.
 *
 * Validación SERVIDOR (única fuente de verdad; el atributo pattern de HTML
 * es solo una ayuda de interfaz). Devuelve un array con las reglas que la
 * contraseña NO cumple; vacío = contraseña válida.
 *
 * Se considera "carácter especial" únicamente los símbolos de la lista
 * explícita [!@#$%^&*()_+-=[]{};:,.<>?]; las letras acentuadas NO cuentan.
 * Quedan prohibidos los espacios en blanco.
 *
 * Cada elemento es un fragmento para componer mensajes como:
 *   "La contraseña debe tener al menos 8 caracteres, incluir al menos una letra mayúscula."
 *
 * Uso:
 *   require_once __DIR__ . '/politica_password.php';
 *   $errores = validarPoliticaPassword($password);
 *   if ($errores) { $error = 'La contraseña debe ' . implode(', ', $errores) . '.'; }
 */

function validarPoliticaPassword(string $password): array
{
    $errores = [];

    if (mb_strlen($password) < 8) {
        $errores[] = 'tener al menos 8 caracteres';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errores[] = 'incluir al menos una letra mayúscula';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errores[] = 'incluir al menos una letra minúscula';
    }

    if (preg_match('/\s/', $password)) {
        $errores[] = 'no debe contener espacios';
    }

    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:,.<>?]/', $password)) {
        $errores[] = 'incluir al menos un carácter especial (por ejemplo: ! @ # $ % & *)';
    }

    return $errores;
}
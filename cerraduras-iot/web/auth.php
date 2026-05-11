<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['username'])) {
        header('Location: login.php');
        exit();
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: index.php?error=forbidden');
        exit();
    }
}

function currentUser(): string
{
    return $_SESSION['username'] ?? '';
}

function currentRole(): string
{
    return $_SESSION['role'] ?? 'user';
}

function isAdmin(): bool
{
    return currentRole() === 'admin';
}

function isViewer(): bool
{
    return currentRole() === 'viewer';
}

/**
 * Comprueba si el usuario puede interactuar con cerraduras.
 * Admin: siempre. Viewer: nunca. User: depende del permiso sobre el plano concreto.
 * Para verificación genérica (sin plano), user puede en principio (restricción a nivel de plano).
 */
function canInteractGeneric(): bool
{
    if (isAdmin())  return true;
    if (isViewer()) return false;
    return true; // user: depende del plano (se verifica en JS y en API por plan)
}

$auth_user = currentUser();
$auth_role = currentRole();
$is_admin  = isAdmin();
$is_viewer = isViewer();
?>

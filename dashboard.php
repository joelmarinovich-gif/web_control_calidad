<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : $_SESSION['email'];
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Control de Calidad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-5">
      <div class="row">
        <div class="col-md-8 mx-auto">
          <div class="card">
            <div class="card-body">
              <h4 class="card-title">Bienvenido, <?php echo htmlspecialchars($name); ?></h4>
              <p class="card-text">Has ingresado al panel de control.</p>
              <a href="logout.php" class="btn btn-outline-secondary">Cerrar Sesión</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>

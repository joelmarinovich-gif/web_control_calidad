<?php
// Simple login page (Bootstrap 5)
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Control de Calidad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      body, html { height: 100%; }
      .card-center { min-height: 100%; display: flex; align-items: center; justify-content: center; }
    </style>
  </head>
  <body class="bg-light">
    <div class="container card-center">
      <div class="row w-100">
        <div class="col-lg-4 col-md-6 mx-auto">
          <div class="card shadow-sm">
            <div class="card-body p-4">
              <h4 class="card-title mb-3 text-center">Control de Calidad - Login</h4>
              <form action="login_process.php" method="post">
                <div class="mb-3">
                  <label for="email" class="form-label">Email</label>
                  <input type="email" class="form-control" id="email" name="email" required autofocus>
                </div>
                <div class="mb-3">
                  <label for="password" class="form-label">Contraseña</label>
                  <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid">
                  <button type="submit" class="btn btn-primary">Ingresar</button>
                </div>
              </form>
            </div>
          </div>
          <p class="text-center text-muted mt-3">&copy; Sistema EQAS - Neisseria gonorrhoeae</p>
        </div>
      </div>
    </div>
  </body>
</html>

<?php
session_start();

// Try to get sucursales from DB, otherwise use fallback data
$sucursales = [];
$db_connected = false;

if (file_exists(__DIR__ . '/config/database.php')) {
    try {
        require_once __DIR__ . '/config/database.php';
        $db = getDB();
        $stmt = $db->query("SELECT * FROM sucursales WHERE activo = 1 ORDER BY ciudad, nombre");
        $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $db_connected = true;
    } catch (Exception $e) {
        // Fallback below
    }
}

if (!$db_connected || empty($sucursales)) {
    // Placeholder data
    $sucursales = [
        [
            'nombre' => 'Central La Paz',
            'ciudad' => 'La Paz',
            'direccion' => 'Av. 6 de Agosto #1234, Sopocachi',
            'telefono' => '+591 2 2441234',
            'horario' => '08:00 - 14:00'
        ],
        [
            'nombre' => 'Sucursal Calacoto',
            'ciudad' => 'La Paz',
            'direccion' => 'Av. Ballivián #567, esq. C. 12',
            'telefono' => '+591 2 2794567',
            'horario' => '08:00 - 14:00'
        ],
        [
            'nombre' => 'Central Santa Cruz',
            'ciudad' => 'Santa Cruz',
            'direccion' => 'Av. Monseñor Rivero #890',
            'telefono' => '+591 3 3338901',
            'horario' => '08:00 - 13:00'
        ],
        [
            'nombre' => 'Sucursal Equipetrol',
            'ciudad' => 'Santa Cruz',
            'direccion' => 'Av. San Martín #432',
            'telefono' => '+591 3 3444321',
            'horario' => '08:00 - 13:00'
        ],
        [
            'nombre' => 'Central Cochabamba',
            'ciudad' => 'Cochabamba',
            'direccion' => 'El Prado, Av. Ballivián #234',
            'telefono' => '+591 4 4222345',
            'horario' => '08:00 - 13:30'
        ],
        [
            'nombre' => 'Sucursal Cala Cala',
            'ciudad' => 'Cochabamba',
            'direccion' => 'Av. Libertador Bolívar #789',
            'telefono' => '+591 4 4337890',
            'horario' => '08:00 - 13:30'
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PATUJU | La Mejor Salteña de Bolivia</title>
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="#" class="logo">🥟 PATUJU</a>
            
            <div class="nav-links" id="navLinks">
                <a href="#inicio">Inicio</a>
                <a href="#nosotros">Nosotros</a>
                <a href="#productos">Productos</a>
                <a href="#sucursales">Sucursales</a>
                <?php if (isset($_SESSION['usuario_id'])): ?>
                    <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                        <a href="admin.php" class="btn btn-primary" style="margin-left: 1rem;">⚙️ Admin</a>
                    <?php else: ?>
                        <a href="index.php" class="btn btn-primary" style="margin-left: 1rem;">🧾 Ir a Caja</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline" style="margin-left: 0.5rem; border-color: var(--color-danger); color: var(--color-danger);">Salir</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary" style="margin-left: 1rem;">Ingresar</a>
                <?php endif; ?>
            </div>

            <button class="mobile-menu-btn" id="mobileMenuBtn">
                ☰
            </button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="inicio">
        <div class="container">
            <div class="hero-content fade-in">
                <h1>La Mejor <span>Salteña</span> de Bolivia</h1>
                <p>Tradición, sabor y calidad desde 1995</p>
                <?php if (isset($_SESSION['usuario_id'])): ?>
                    <a href="<?= $_SESSION['usuario_rol'] === 'admin' ? 'admin.php' : 'index.php' ?>" class="btn btn-primary">
                        Ir al Sistema (<?= htmlspecialchars($_SESSION['usuario_nombre']) ?>)
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Ingresar al Sistema</a>
                <?php endif; ?>
            </div>
            <div class="hero-img fade-in" style="margin-top:2rem;text-align:center;">
                <img src="assets/img/landing/hero_saltenas.svg"
                     alt="Salteñas Patuju — Tradición Boliviana"
                     style="max-width:480px;width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5);">
            </div>
        </div>
    </section>

    <!-- Nosotros Section -->
    <section id="nosotros">
        <div class="container">
            <div class="nosotros-grid">
                <div class="nosotros-text fade-in">
                    <h2>Nuestra <span>Historia</span></h2>
                    <p>Más de 25 años llevando el sabor de la salteña boliviana a cada rincón del país. Nuestro compromiso es mantener la receta tradicional que ha enamorado a generaciones.</p>
                    <p>Seleccionamos cuidadosamente cada ingrediente para garantizar la jugosidad, el equilibrio perfecto de sabores y esa masa horneada crujiente que nos caracteriza.</p>
                    
                    <div class="stats">
                        <div class="stat-item">
                            <h4>15</h4>
                            <p>Sucursales</p>
                        </div>
                        <div class="stat-item">
                            <h4>+25</h4>
                            <p>Años de Tradición</p>
                        </div>
                        <div class="stat-item">
                            <h4>+1M</h4>
                            <p>Salteñas Vendidas</p>
                        </div>
                        <div class="stat-item">
                            <h4>100%</h4>
                            <p>Boliviano</p>
                        </div>
                    </div>
                </div>
                <div class="nosotros-img fade-in">
                    <img src="assets/img/landing/nosotros_cocina.svg" alt="Cocina Patuju — Tradición artesanal">
                </div>
            </div>
        </div>
    </section>

    <!-- Productos Section -->
    <section id="productos" style="background-color: var(--color-surface-2);">
        <div class="container">
            <h2 class="fade-in">Nuestros <span>Productos</span></h2>
            
            <div class="productos-grid">
                <div class="producto-card fade-in">
                    <img src="assets/img/landing/producto_saltena.svg" alt="Salteña de Carne" class="producto-img">
                    <div class="producto-info">
                        <h3>🥟 Salteña de Carne</h3>
                        <p>La clásica salteña boliviana con jigote de carne de res</p>
                    </div>
                </div>
                
                <div class="producto-card fade-in" style="transition-delay: 0.1s;">
                    <img src="assets/img/landing/producto_saltena.svg" alt="Salteña de Pollo" class="producto-img">
                    <div class="producto-info">
                        <h3>🥟 Salteña de Pollo</h3>
                        <p>Salteña jugosa de pollo, la favorita de todos</p>
                    </div>
                </div>
                
                <div class="producto-card fade-in" style="transition-delay: 0.2s;">
                    <img src="assets/img/landing/producto_tucumana.svg" alt="Tucumanas" class="producto-img">
                    <div class="producto-info">
                        <h3>🥟 Tucumanas</h3>
                        <p>Crujientes tucumanas fritas, perfectas para acompañar</p>
                    </div>
                </div>
                
                <div class="producto-card fade-in" style="transition-delay: 0.3s;">
                    <img src="assets/img/landing/producto_jugo.svg" alt="Jugos Naturales" class="producto-img">
                    <div class="producto-info">
                        <h3>🍊 Jugos Naturales</h3>
                        <p>Jugos frescos de frutas tropicales bolivianas</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Sucursales Section -->
    <section id="sucursales">
        <div class="container">
            <h2 class="fade-in">Nuestras <span>15 Sucursales</span></h2>
            
            <div class="sucursales-grid">
                <?php foreach($sucursales as $index => $sucursal): ?>
                <div class="sucursal-card fade-in" style="transition-delay: <?php echo ($index % 3) * 0.1; ?>s;">
                    <div class="sucursal-header">
                        <div class="sucursal-icon">📍</div>
                        <div>
                            <h3 style="font-size: 1.1rem; margin-bottom: 0.2rem;"><?php echo htmlspecialchars($sucursal['nombre']); ?></h3>
                            <span style="color: var(--color-text-muted); font-size: 0.8rem;"><?php echo htmlspecialchars($sucursal['ciudad']); ?></span>
                        </div>
                    </div>
                    <div class="sucursal-info">
                        <p><strong>Dirección:</strong> <?php echo htmlspecialchars($sucursal['direccion']); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($sucursal['telefono'] ?? 'No disponible'); ?></p>
                        <p><strong>Horario:</strong> <?php echo htmlspecialchars($sucursal['horario'] ?? '08:00 - 13:00'); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <a href="#" class="logo" style="margin-bottom: 1rem;">🥟 PATUJU</a>
                    <p>Tradición, sabor y calidad desde 1995. La mejor salteña de Bolivia, horneada diariamente para ti.</p>
                </div>
                
                <div class="footer-col">
                    <h4>Enlaces Rápidos</h4>
                    <ul class="footer-links">
                        <li><a href="#inicio">Inicio</a></li>
                        <li><a href="#nosotros">Nosotros</a></li>
                        <li><a href="#productos">Productos</a></li>
                        <li><a href="#sucursales">Sucursales</a></li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h4>Contacto</h4>
                    <p>Av. 6 de Agosto #1234<br>La Paz, Bolivia</p>
                    <p>Tel: +591 2 2441234</p>
                    <p>Email: info@patuju.com.bo</p>
                </div>
            </div>
            
            <div class="footer-bottom">
                &copy; 2024 Patuju — Todos los derechos reservados.
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script>
        // Mobile Menu
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const navLinks = document.getElementById('navLinks');
        
        mobileBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });

        // Close mobile menu when clicking a link
        navLinks.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    navLinks.classList.remove('active');
                }
            });
        });

        // Intersection Observer for Animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: "0px 0px -50px 0px"
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('appear');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in').forEach(element => {
            observer.observe(element);
        });
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Web System | Landing Page</title>
  <link rel="stylesheet" href="../css/landingpage.css"/>
  
</head>
<body>
  <header>
    <!-- logo path corrected relative to Landingpage folder -->
    <h1><img src="../images/Enrile.png" alt="Logo" class="header-logo" />E-Leave Mo</h1>
    <nav>
      <a href="#features">Features</a>
      <a href="#about">About</a>
      <a href="#screenshots">Screenshots</a>
      <a href="#contact">Contact</a>
      <a href="../Login/login.php">Login</a>
    </nav>
  </header>

  <style>
    :root {
  --primary: #2563eb;
  --secondary: #0f172a;
  --accent: #22c55e;
  --light: #f8fafc;
  --gray: #64748b;
}

* { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
body { background: var(--light); color: var(--secondary); line-height: 1.6; }

header {
  background: white;
  padding: 15px 40px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
  position: sticky;
  top: 0;
}

/* logo shown next to heading */
.header-logo {
  height: 1.6em; /* roughly same size as text */
  max-height: 28px;
  margin-right: 10px;
  vertical-align: middle;
}

nav a {
  margin-left: 20px;
  text-decoration: none;
  color: var(--secondary);
  font-weight: bold;
}

.hero {
  padding: 120px 40px;
  text-align: center;
  background-image: url('../images/Municipal_Hall_of_Enrile,_Cagayan.jpg');
  background-size: cover;
  background-position: center top;
  background-repeat: no-repeat;
  color: white;
  position: relative;
}

.hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(10, 30, 60, 0.52);
}

.hero h1 { font-size: 3rem; margin-bottom: 20px; position: relative; z-index: 1; }
.hero p { font-size: 1.2rem; margin-bottom: 30px; position: relative; z-index: 1; }
.hero .btn { position: relative; z-index: 1; }

.btn {
  background: white;
  color: var(--primary);
  padding: 12px 25px;
  border-radius: 6px;
  text-decoration: none;
  font-weight: bold;
}

section { padding: 60px 40px; }

.features {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-top: 30px;
}

.card {
  background: white;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}

.about { text-align: center; }

.screenshots {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
}

.placeholder {
  height: 200px;
  background: #e2e8f0;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--gray);
}

footer {
  background: var(--secondary);
  color: white;
  text-align: center;
  padding: 20px;
}

@media(max-width: 768px) {
  .hero h1 { font-size: 2rem; }
}

  </style>

  

  <section class="hero">
    <h1>Welcome to My Web System</h1>
    <p>A modern solution designed to simplify management and improve efficiency.</p>
    <a href="../Login/login.php" class="btn">Get Started</a>
  </section>

  <section id="features">
    <h2>System Features</h2>
    <div class="features">
      <div class="card">
        <h3>User Management</h3>
        <p>Manage users, roles, and permissions efficiently.</p>
      </div>
      <div class="card">
        <h3>Data Management</h3>
        <p>Securely store and organize important system data.</p>
      </div>
      <div class="card">
        <h3>Reports & Analytics</h3>
        <p>Generate real-time reports for better decision-making.</p>
      </div>
    </div>
  </section>

  <section id="about" class="about">
    <h2>About the System</h2>
    <p>This web-based system is developed to automate processes, improve accessibility, and enhance overall operational performance.</p>
  </section>

  <section id="screenshots">
    <h2>System Preview</h2>
    <div class="screenshots">
      <div class="placeholder">Screenshot 1</div>
      <div class="placeholder">Screenshot 2</div>
      <div class="placeholder">Screenshot 3</div>
    </div>
  </section>

  <section id="contact" class="about">
    <h2>Contact Us</h2>
    <p>Email: info@mysystem.com</p>
    <p>Phone: +63 900 000 0000</p>
  </section>

  <footer>
    <p>&copy; 2026 My Web System. All rights reserved.</p>
  </footer>
</body>
</html>
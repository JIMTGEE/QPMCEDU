<nav class="navbar navbar-expand-lg navbar-dark animate__animated animate__fadeInDown">
    <div class="container">
        <a class="navbar-brand" href="/queenspa/public/index.php">Queenest Paradise Montessori Complex
        </br>
        QUEENSPA</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="about.php">About</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admissions.php">Admissions</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="contact.php">Contact</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="login.php">Login</a>
                </li>
            </ul>
            <button class="btn theme-toggle ms-3" onclick="toggleTheme()" aria-label="Toggle theme">🌙</button>
        </div>
    </div>
</nav>

<script>
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        document.body.classList.toggle('night-mode');
    }

    window.addEventListener('DOMContentLoaded', () => {
        const saved = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', saved);
        if (saved === 'dark') document.body.classList.add('night-mode');
    });
</script>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Unavailable - PrimeHub</title>
    <style>
        /* --- Theme Configuration (Based on your Image) --- */
        :root {
            --bg-color: #02040a;       /* Deepest Black/Blue */
            --accent-blue: #3b82f6;    /* PrimeHub Blue */
            --text-white: #ffffff;
            --text-gray: #94a3b8;
            --card-bg: rgba(15, 23, 42, 0.6); /* Glassy Slate */
        }

        /* --- Reset & Layout --- */
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            background-color: var(--bg-color);
            /* Subtle radial gradient to match the top-left glow in your image */
            background-image: radial-gradient(circle at 20% 20%, #1e3a8a 0%, #02040a 50%);
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: var(--text-white);
        }

        #canvas-layer {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
        }

        .content-layer {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
            padding: 20px;
            box-sizing: border-box;
        }

        /* --- Branding --- */
        .brand-logo {
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: -1px;
            margin-bottom: 1rem;
            text-transform: uppercase;
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        .brand-prime { color: white; }
        .brand-hub {
            color: var(--accent-blue);
            text-shadow: 0 0 20px rgba(59, 130, 246, 0.5);
        }

        /* --- Typography --- */
        h2 {
            font-size: 2.5rem;
            font-weight: 600;
            margin: 0 0 1rem 0;
        }

        .status-pill {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--accent-blue);
            padding: 0.5rem 1.5rem;
            border-radius: 99px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 2rem;
            display: inline-block;
            backdrop-filter: blur(5px);
        }

        .description {
            color: var(--text-gray);
            max-width: 500px;
            margin: 0 auto 1rem;
            line-height: 1.6;
            font-size: 1rem;
        }

        /* --- Quote Block (Matching your Left Panel) --- */
        .quote-container {
            margin-top: 3rem;
            max-width: 600px;
            border-left: 4px solid var(--accent-blue);
            padding-left: 1.5rem;
            text-align: left;
            opacity: 0.8;
        }

        .quote-text {
            font-style: italic;
            font-size: 1.1rem;
            color: var(--text-gray);
            line-height: 1.6;
            transition: opacity 0.5s ease-in-out;
        }

        .quote-text.fade-out {
            opacity: 0;
        }

        .quote-mark {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--text-gray);
            margin-right: 5px;
        }

        .quote-container {
            animation: fadeInUp 1s ease-out 0.5s both;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 0.8;
                transform: translateY(0);
            }
        }

        /* --- Footer --- */
        .footer {
            margin-top: 2rem;
            padding-bottom: 1.5rem;
            font-size: 0.8rem;
            color: var(--text-gray);
            opacity: 0.6;
        }
    </style>
</head>
<body>

    <canvas id="canvas-layer"></canvas>

    <div class="content-layer">
        <div class="brand-logo">
            <span class="brand-prime">PRIME</span><span class="brand-hub">hub</span>
        </div>

        <div class="status-pill">
            System Maintenance
        </div>

        <h2>We'll be right back.</h2>

        <p class="description">
            @if(isset($exception) && $exception->getMessage())
                {{ $exception->getMessage() }}
            @else
                Our systems are currently undergoing scheduled improvements.
                Interact with the background while you wait.
            @endif
        </p>

        <div class="quote-container">
            <div class="quote-text" id="quote-display">
                <span class="quote-mark">"</span>
                <span id="quote-content">Your only limit is your mind. Push past the doubt; your potential is infinite. Start today, not tomorrow.</span>
                <span class="quote-mark">”</span>
            </div>
        </div>

        <div class="footer">
            Powered by PrimeHub Systems
        </div>
    </div>

    <script>
        /**
         * ANIMATION: Floating Squares (Matching the Cube Logo)
         * Connected by network lines.
         */
        const canvas = document.getElementById('canvas-layer');
        const ctx = canvas.getContext('2d');

        let width, height;
        let particles = [];
        const particleCount = 50;
        const connectionDistance = 150;
        const mouseDistance = 250;

        let mouse = { x: null, y: null };

        // Resize Logic
        function resize() {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        }
        window.addEventListener('resize', resize);
        resize();

        // Mouse Tracking
        window.addEventListener('mousemove', (e) => {
            mouse.x = e.x;
            mouse.y = e.y;
        });
        window.addEventListener('touchmove', (e) => {
            mouse.x = e.touches[0].clientX;
            mouse.y = e.touches[0].clientY;
        });

        // Particle Object (Square Shape)
        class Particle {
            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 1;
                this.vy = (Math.random() - 0.5) * 1;
                this.size = Math.random() * 8 + 4; // Larger squares
                // Blue shades based on your logo
                this.color = Math.random() > 0.5 ? 'rgba(59, 130, 246, 0.4)' : 'rgba(30, 58, 138, 0.4)';
                this.angle = 0;
                this.spinSpeed = (Math.random() - 0.5) * 0.05;
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.angle += this.spinSpeed;

                // Bounce off edges
                if (this.x < 0 || this.x > width) this.vx *= -1;
                if (this.y < 0 || this.y > height) this.vy *= -1;

                // Mouse Repel/Interact
                if (mouse.x != null) {
                    let dx = mouse.x - this.x;
                    let dy = mouse.y - this.y;
                    let distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < mouseDistance) {
                        const forceDirectionX = dx / distance;
                        const forceDirectionY = dy / distance;
                        const force = (mouseDistance - distance) / mouseDistance;
                        const directionX = forceDirectionX * force * 2;
                        const directionY = forceDirectionY * force * 2;
                        this.x -= directionX;
                        this.y -= directionY;
                    }
                }
            }

            draw() {
                ctx.save();
                ctx.translate(this.x, this.y);
                ctx.rotate(this.angle);
                ctx.fillStyle = this.color;
                // Draw Square instead of Circle
                ctx.fillRect(-this.size / 2, -this.size / 2, this.size, this.size);
                ctx.restore();
            }
        }

        function init() {
            particles = [];
            for (let i = 0; i < particleCount; i++) {
                particles.push(new Particle());
            }
        }

        function animate() {
            ctx.clearRect(0, 0, width, height);

            for (let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();

                // Draw Network Lines
                for (let j = i; j < particles.length; j++) {
                    let dx = particles[i].x - particles[j].x;
                    let dy = particles[i].y - particles[j].y;
                    let distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < connectionDistance) {
                        ctx.beginPath();
                        // Very faint blue lines
                        ctx.strokeStyle = `rgba(59, 130, 246, ${0.2 - (distance / connectionDistance) * 0.2})`;
                        ctx.lineWidth = 1;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                    }
                }

                // Draw Mouse Connections
                if (mouse.x != null) {
                    let dx = particles[i].x - mouse.x;
                    let dy = particles[i].y - mouse.y;
                    let distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < connectionDistance) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(255, 255, 255, ${0.2 - distance / connectionDistance})`;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(animate);
        }

        init();
        animate();

        // Rotating Quotes Animation
        const quotes = [
            "Your only limit is your mind. Push past the doubt; your potential is infinite. Start today, not tomorrow.",
            "Great things are built one line of code at a time. Keep building, keep growing.",
            "Every expert was once a beginner. Your journey to excellence starts with a single step.",
            "Innovation distinguishes between a leader and a follower. Be the leader.",
            "The best way to predict the future is to create it. We're creating something amazing.",
            "Success is not final, failure is not fatal: it is the courage to continue that counts.",
            "The only way to do great work is to love what you do. We love what we build.",
            "Stay hungry, stay foolish. Never stop learning, never stop improving."
        ];

        let currentQuoteIndex = 0;
        const quoteContent = document.getElementById('quote-content');
        const quoteDisplay = document.getElementById('quote-display');

        function rotateQuote() {
            quoteDisplay.classList.add('fade-out');

            setTimeout(() => {
                currentQuoteIndex = (currentQuoteIndex + 1) % quotes.length;
                quoteContent.textContent = quotes[currentQuoteIndex];
                quoteDisplay.classList.remove('fade-out');
            }, 500);
        }

        setInterval(rotateQuote, 6000);

        // Auto-refresh when maintenance mode is disabled
        let isChecking = false;
        function checkMaintenanceStatus() {
            if (isChecking) return; // Prevent overlapping requests
            isChecking = true;

            fetch(window.location.href, {
                method: 'HEAD',
                cache: 'no-store'
            })
            .then(response => {
                isChecking = false;
                // If we get a 200 OK (not 503), maintenance is over
                if (response.ok) {
                    window.location.reload();
                }
            })
            .catch(() => {
                isChecking = false;
                // Network error, site still down - do nothing
            });
        }

        // Check every 10 seconds if maintenance is over
        setInterval(checkMaintenanceStatus, 10000);
    </script>
</body>
</html>

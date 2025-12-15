<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - PrimeHub</title>
    <style>
        /* --- Theme Configuration --- */
        :root {
            --bg-color: #02040a;
            --accent-color: #8b5cf6;
            --accent-dark: #5b21b6;
            --text-white: #ffffff;
            --text-gray: #94a3b8;
            --card-bg: rgba(15, 23, 42, 0.6);
        }

        /* --- Reset & Layout --- */
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            background-color: var(--bg-color);
            background-image: radial-gradient(circle at 20% 20%, #5b21b6 0%, #02040a 50%);
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
            color: var(--accent-color);
            text-shadow: 0 0 20px rgba(139, 92, 246, 0.5);
        }

        /* --- Typography --- */
        .error-code {
            font-size: 8rem;
            font-weight: 700;
            color: var(--accent-color);
            line-height: 1;
            margin: 0;
            text-shadow: 0 0 40px rgba(139, 92, 246, 0.3);
        }

        h2 {
            font-size: 2.5rem;
            font-weight: 600;
            margin: 0.5rem 0 1rem 0;
        }

        .status-pill {
            background: rgba(139, 92, 246, 0.1);
            border: 1px solid rgba(139, 92, 246, 0.3);
            color: var(--accent-color);
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
            margin: 0 auto 2rem;
            line-height: 1.6;
        }

        /* --- Button --- */
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--accent-dark) 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 500;
            font-size: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -5px rgba(139, 92, 246, 0.4);
        }

        .btn-home svg {
            width: 20px;
            height: 20px;
        }

        /* --- Quote Block --- */
        .quote-container {
            margin-top: 3rem;
            max-width: 600px;
            border-left: 4px solid var(--accent-color);
            padding-left: 1.5rem;
            text-align: left;
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
            Page Not Found
        </div>

        <div class="error-code">404</div>

        <h2>Lost in Space</h2>

        <p class="description">
            Oops! The page you're looking for doesn't exist or has been moved to another dimension.
        </p>

        <a href="javascript:history.back()" class="btn-home">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Go Back
        </a>

        <div class="quote-container">
            <div class="quote-text" id="quote-display">
                <span class="quote-mark">"</span>
                <span id="quote-content">Not all who wander are lost, but this page definitely is.</span>
                <span class="quote-mark">"</span>
            </div>
        </div>

        <div class="footer">
            Powered by PrimeHub Systems
        </div>
    </div>

    <script>
        const canvas = document.getElementById('canvas-layer');
        const ctx = canvas.getContext('2d');

        let width, height;
        let particles = [];
        const particleCount = 50;
        const connectionDistance = 150;
        const mouseDistance = 250;

        let mouse = { x: null, y: null };

        function resize() {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        }
        window.addEventListener('resize', resize);
        resize();

        window.addEventListener('mousemove', (e) => {
            mouse.x = e.x;
            mouse.y = e.y;
        });
        window.addEventListener('touchmove', (e) => {
            mouse.x = e.touches[0].clientX;
            mouse.y = e.touches[0].clientY;
        });

        class Particle {
            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 1;
                this.vy = (Math.random() - 0.5) * 1;
                this.size = Math.random() * 8 + 4;
                this.color = Math.random() > 0.5 ? 'rgba(139, 92, 246, 0.4)' : 'rgba(91, 33, 182, 0.4)';
                this.angle = 0;
                this.spinSpeed = (Math.random() - 0.5) * 0.05;
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.angle += this.spinSpeed;

                if (this.x < 0 || this.x > width) this.vx *= -1;
                if (this.y < 0 || this.y > height) this.vy *= -1;

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

                for (let j = i; j < particles.length; j++) {
                    let dx = particles[i].x - particles[j].x;
                    let dy = particles[i].y - particles[j].y;
                    let distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < connectionDistance) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(139, 92, 246, ${0.2 - (distance / connectionDistance) * 0.2})`;
                        ctx.lineWidth = 1;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                    }
                }

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
            "Not all who wander are lost, but this page definitely is.",
            "The best journeys sometimes take unexpected detours.",
            "Every wrong turn is a chance to discover something new.",
            "Lost pages are just adventures waiting to be found.",
            "Sometimes the path less traveled doesn't exist at all."
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
    </script>
</body>
</html>

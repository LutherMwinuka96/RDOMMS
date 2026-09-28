/**
 * Animated particle network for auth pages (sign in / sign up).
 */
(function () {
  const canvas = document.getElementById('authParticlesCanvas');
  if (!canvas) return;

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReduced) return;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  let width = 0;
  let height = 0;
  let particles = [];
  let animationId = 0;
  let running = true;

  const config = {
    density: 8500,
    minSize: 1.4,
    maxSize: 3.6,
    speed: 0.42,
    linkDistance: 130,
    linkOpacity: 0.2,
    pulseSpeed: 0.018
  };

  function resize() {
    width = window.innerWidth;
    height = window.innerHeight;
    canvas.width = width;
    canvas.height = height;

    const count = Math.max(24, Math.min(90, Math.floor((width * height) / config.density)));
    particles = Array.from({ length: count }, () => createParticle());
  }

  function createParticle() {
    return {
      x: Math.random() * width,
      y: Math.random() * height,
      vx: (Math.random() - 0.5) * config.speed,
      vy: (Math.random() - 0.5) * config.speed,
      size: config.minSize + Math.random() * (config.maxSize - config.minSize),
      baseAlpha: 0.35 + Math.random() * 0.55,
      alpha: 0.35 + Math.random() * 0.55,
      pulse: Math.random() * Math.PI * 2,
      hue: Math.random() > 0.78 ? 42 : 142
    };
  }

  function step() {
    if (!running) return;

    ctx.clearRect(0, 0, width, height);

    for (let i = 0; i < particles.length; i += 1) {
      const p = particles[i];
      p.x += p.vx;
      p.y += p.vy;
      p.pulse += config.pulseSpeed;
      p.alpha = p.baseAlpha + Math.sin(p.pulse) * 0.12;

      if (p.x <= 0 || p.x >= width) p.vx *= -1;
      if (p.y <= 0 || p.y >= height) p.vy *= -1;

      ctx.beginPath();
      ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
      ctx.fillStyle = `hsla(${p.hue}, 72%, 62%, ${p.alpha})`;
      ctx.fill();

      for (let j = i + 1; j < particles.length; j += 1) {
        const other = particles[j];
        const dx = p.x - other.x;
        const dy = p.y - other.y;
        const dist = Math.hypot(dx, dy);
        if (dist > config.linkDistance) continue;

        const opacity = config.linkOpacity * (1 - dist / config.linkDistance);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(other.x, other.y);
        ctx.strokeStyle = `rgba(134, 239, 172, ${opacity})`;
        ctx.lineWidth = 0.8;
        ctx.stroke();
      }
    }

    animationId = window.requestAnimationFrame(step);
  }

  function start() {
    running = true;
    resize();
    step();
  }

  function stop() {
    running = false;
    window.cancelAnimationFrame(animationId);
  }

  window.addEventListener('resize', resize, { passive: true });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else start();
  });

  start();
})();

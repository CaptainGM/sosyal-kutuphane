// Giriş ekranının 3D arka planı: parçacık alanı ve dönen tel kafes (Three.js).
// WebGL yoksa, CDN'e ulaşılamazsa veya hareket azaltma tercihi açıksa (prefers-reduced-motion)
// sessizce atlanır; sayfa CSS arka planıyla çalışır.
// Yalnızca index.html yükler. Diğer sayfalar CSS 3D (poster eğilmesi) kullanır.
(function () {
    const THREE_URL = 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isSmallScreen = window.matchMedia('(max-width: 768px)').matches;

    function hasWebGL() {
        try {
            const probe = document.createElement('canvas');
            return !!(probe.getContext('webgl2') || probe.getContext('webgl'));
        } catch (err) {
            return false;
        }
    }

    async function init() {
        if (reduceMotion || !hasWebGL()) return;

        let THREE;
        try {
            THREE = await import(THREE_URL);
        } catch (err) {
            console.warn('3D sahne yüklenemedi, CSS arka planı kullanılıyor.', err);
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.id = 'scene3d';
        canvas.setAttribute('aria-hidden', 'true');
        document.body.prepend(canvas);

        const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.75));

        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(55, 1, 0.1, 100);
        camera.position.z = 9;

        // Parçacık alanı: küresel kabukta dağılmış, renkli ve yavaş dönen noktalar.
        const count = isSmallScreen ? 450 : 1100;
        const positions = new Float32Array(count * 3);
        const colors = new Float32Array(count * 3);
        const palette = ['#818cf8', '#c084fc', '#f472b6', '#22d3ee'].map(hex => new THREE.Color(hex));

        for (let i = 0; i < count; i++) {
            const radius = 6 + Math.random() * 10;
            const theta = Math.random() * Math.PI * 2;
            const phi = Math.acos(2 * Math.random() - 1);
            positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
            positions[i * 3 + 1] = radius * Math.sin(phi) * Math.sin(theta);
            positions[i * 3 + 2] = radius * Math.cos(phi);

            const color = palette[i % palette.length];
            colors[i * 3] = color.r;
            colors[i * 3 + 1] = color.g;
            colors[i * 3 + 2] = color.b;
        }

        const pointsGeometry = new THREE.BufferGeometry();
        pointsGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        pointsGeometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        const points = new THREE.Points(pointsGeometry, new THREE.PointsMaterial({
            size: 0.09,
            vertexColors: true,
            transparent: true,
            opacity: 1,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
        }));
        scene.add(points);

        // Tel kafes düğüm ve dış kabuk: arka planda derinlik hissi veren 3D odak noktası.
        const focusPosition = new THREE.Vector3(-6.6, -3.6, -6);
        const knot = new THREE.Mesh(
            new THREE.TorusKnotGeometry(1.15, 0.34, 220, 28),
            new THREE.MeshBasicMaterial({ color: 0x8b8dff, wireframe: true, transparent: true, opacity: 0.38 })
        );
        knot.position.copy(focusPosition);
        scene.add(knot);

        const shell = new THREE.Mesh(
            new THREE.IcosahedronGeometry(2.4, 1),
            new THREE.MeshBasicMaterial({ color: 0xc084fc, wireframe: true, transparent: true, opacity: 0.16 })
        );
        shell.position.copy(focusPosition);
        scene.add(shell);

        // Fare konumu kamerayı hafifçe kaydırır (paralaks).
        let pointerX = 0;
        let pointerY = 0;
        window.addEventListener('pointermove', (e) => {
            pointerX = e.clientX / window.innerWidth - 0.5;
            pointerY = e.clientY / window.innerHeight - 0.5;
        }, { passive: true });

        function resize() {
            const width = window.innerWidth;
            const height = window.innerHeight;
            renderer.setSize(width, height, false);
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
        }

        const clock = new THREE.Clock();
        let frameId = 0;

        function render() {
            const t = clock.getElapsedTime();
            points.rotation.y = t * 0.02 + pointerX * 0.25;
            points.rotation.x = pointerY * 0.15;
            knot.rotation.x = t * 0.18;
            knot.rotation.y = t * 0.12;
            shell.rotation.x = -t * 0.08;
            shell.rotation.y = t * 0.1;

            camera.position.x += (pointerX * 1.2 - camera.position.x) * 0.04;
            camera.position.y += (-pointerY * 0.8 - camera.position.y) * 0.04;
            camera.lookAt(0, 0, 0);

            renderer.render(scene, camera);
            frameId = requestAnimationFrame(render);
        }

        // Sekme arka plana geçince animasyonu durdur, geri gelince sürdür.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                cancelAnimationFrame(frameId);
                frameId = 0;
            } else if (!frameId) {
                frameId = requestAnimationFrame(render);
            }
        });

        window.addEventListener('resize', resize, { passive: true });
        resize();
        render();
    }

    init();
})();

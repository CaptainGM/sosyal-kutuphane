// Giriş ekranının 3D arka planı: parçacık alanı ve dönen tel kafes (Three.js).
// Tema değişince (themechange olayı) renkler ve karışım modu yeniden uygulanır:
// koyu temada parlayan (toplamalı) noktalar, açık temada koyu ve doygun noktalar.
// WebGL yoksa, CDN erişilemezse ya da hareket azaltma açıksa sessizce atlanır.
(function () {
    const THREE_URL = 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isSmallScreen = window.matchMedia('(max-width: 768px)').matches;

    const PALETTES = {
        dark: {
            points: ['#818cf8', '#c084fc', '#f472b6', '#22d3ee'],
            knot: 0x8b8dff, knotOpacity: 0.38,
            shell: 0xc084fc, shellOpacity: 0.16,
            pointOpacity: 1,
        },
        light: {
            points: ['#4f46e5', '#9333ea', '#db2777', '#0e7490'],
            knot: 0x4f46e5, knotOpacity: 0.5,
            shell: 0x9333ea, shellOpacity: 0.22,
            pointOpacity: 0.75,
        },
    };

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

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

        // Parçacık alanı: küresel kabukta dağılmış noktalar.
        const count = isSmallScreen ? 450 : 1100;
        const positions = new Float32Array(count * 3);
        const colors = new Float32Array(count * 3);
        for (let i = 0; i < count; i++) {
            const radius = 6 + Math.random() * 10;
            const theta = Math.random() * Math.PI * 2;
            const phi = Math.acos(2 * Math.random() - 1);
            positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
            positions[i * 3 + 1] = radius * Math.sin(phi) * Math.sin(theta);
            positions[i * 3 + 2] = radius * Math.cos(phi);
        }

        const pointsGeometry = new THREE.BufferGeometry();
        pointsGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        pointsGeometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        // Yumuşak yuvarlak nokta dokusu (kare noktalar yerine).
        const dotCanvas = document.createElement('canvas');
        dotCanvas.width = 64;
        dotCanvas.height = 64;
        const dotContext = dotCanvas.getContext('2d');
        const gradient = dotContext.createRadialGradient(32, 32, 0, 32, 32, 32);
        gradient.addColorStop(0, 'rgba(255, 255, 255, 1)');
        gradient.addColorStop(0.45, 'rgba(255, 255, 255, 0.85)');
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
        dotContext.fillStyle = gradient;
        dotContext.fillRect(0, 0, 64, 64);

        const pointsMaterial = new THREE.PointsMaterial({
            size: 0.11,
            map: new THREE.CanvasTexture(dotCanvas),
            vertexColors: true,
            transparent: true,
            depthWrite: false,
        });
        const points = new THREE.Points(pointsGeometry, pointsMaterial);
        scene.add(points);

        // Tel kafes düğüm ve dış kabuk: arka planda derinlik hissi veren 3D odak noktası.
        const focusPosition = new THREE.Vector3(-6.6, -3.6, -6);
        const knotMaterial = new THREE.MeshBasicMaterial({ wireframe: true, transparent: true });
        const knot = new THREE.Mesh(new THREE.TorusKnotGeometry(1.15, 0.34, 220, 28), knotMaterial);
        knot.position.copy(focusPosition);
        scene.add(knot);

        const shellMaterial = new THREE.MeshBasicMaterial({ wireframe: true, transparent: true });
        const shell = new THREE.Mesh(new THREE.IcosahedronGeometry(2.4, 1), shellMaterial);
        shell.position.copy(focusPosition);
        scene.add(shell);

        // Tema renkleri ve karışım modu: ilk açılışta ve her tema değişiminde.
        function applyTheme() {
            const name = currentTheme();
            const config = PALETTES[name];
            const palette = config.points.map(hex => new THREE.Color(hex));
            for (let i = 0; i < count; i++) {
                const color = palette[i % palette.length];
                colors[i * 3] = color.r;
                colors[i * 3 + 1] = color.g;
                colors[i * 3 + 2] = color.b;
            }
            pointsGeometry.attributes.color.needsUpdate = true;

            pointsMaterial.blending = name === 'dark' ? THREE.AdditiveBlending : THREE.NormalBlending;
            pointsMaterial.opacity = config.pointOpacity;
            pointsMaterial.needsUpdate = true;

            knotMaterial.color.setHex(config.knot);
            knotMaterial.opacity = config.knotOpacity;
            knotMaterial.needsUpdate = true;

            shellMaterial.color.setHex(config.shell);
            shellMaterial.opacity = config.shellOpacity;
            shellMaterial.needsUpdate = true;
        }
        applyTheme();
        window.addEventListener('themechange', applyTheme);

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

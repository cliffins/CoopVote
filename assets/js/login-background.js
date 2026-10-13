/* Decorative scene loaded after the sign-in form has painted. */
    (function () {
      if (typeof THREE === 'undefined') return;    // no library, no backdrop
      try {
        const canvas = document.getElementById('three-canvas');
        const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setClearColor(0x000000, 0);

        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(55, innerWidth / innerHeight, 0.1, 200);
        camera.position.set(0, 0, 28);

        function resize() {
          camera.aspect = innerWidth / innerHeight;
          camera.updateProjectionMatrix();
          renderer.setSize(innerWidth, innerHeight);
        }
        resize();
        window.addEventListener('resize', resize);

        /* colors */
        const C_GOLD = new THREE.Color(0xc9a84c);
        const C_GOLD2 = new THREE.Color(0xe8c96a);
        const C_TEAL = new THREE.Color(0x4caf82);
        const C_BLUE = new THREE.Color(0x4a9fd4);
        const C_PURPLE = new THREE.Color(0x9b7fd4);
        const C_NAVY = new THREE.Color(0x162a52);

        /* large floating orbs */
        const orbConfig = [
          { p: [-18, 8, -22], r: 7.5, c: C_GOLD, o: .13 },
          { p: [18, -6, -24], r: 9.5, c: C_NAVY, o: .55 },
          { p: [-6, -12, -18], r: 5.5, c: C_TEAL, o: .1 },
          { p: [8, 14, -28], r: 10, c: C_BLUE, o: .09 },
          { p: [14, 4, -12], r: 3.5, c: C_GOLD2, o: .13 },
          { p: [-12, -4, -14], r: 4.5, c: C_PURPLE, o: .1 },
          { p: [2, 18, -20], r: 6, c: C_TEAL, o: .07 },
        ];
        const orbs = orbConfig.map(d => {
          const m = new THREE.Mesh(
            new THREE.SphereGeometry(d.r, 28, 28),
            new THREE.MeshStandardMaterial({ color: d.c, transparent: true, opacity: d.o, roughness: .8, metalness: .1 })
          );
          m.position.set(...d.p);
          m.userData = { ox: d.p[0], oy: d.p[1], speed: .15 + Math.random() * .22, phase: Math.random() * Math.PI * 2 };
          scene.add(m);
          return m;
        });

        /* torus rings */
        function addTorus(r1, r2, col, op, rx, ry, px, py, pz) {
          const m = new THREE.Mesh(
            new THREE.TorusGeometry(r1, r2, 8, 100),
            new THREE.MeshBasicMaterial({ color: col, transparent: true, opacity: op })
          );
          m.rotation.x = rx; m.rotation.y = ry;
          m.position.set(px, py, pz);
          scene.add(m); return m;
        }
        const t1 = addTorus(9, .06, 0xc9a84c, .18, Math.PI / 3, 0, 12, -4, -10);
        const t2 = addTorus(5.5, .04, 0x4caf82, .13, -Math.PI / 4, Math.PI / 6, -14, 6, -8);
        const t3 = addTorus(14, .03, 0x4a9fd4, .08, Math.PI / 2, Math.PI / 5, 0, 0, -20);

        /* wireframe polyhedra */
        function addWire(geo, col, op, px, py, pz) {
          const m = new THREE.LineSegments(
            new THREE.EdgesGeometry(geo),
            new THREE.LineBasicMaterial({ color: col, transparent: true, opacity: op })
          );
          m.position.set(px, py, pz); scene.add(m); return m;
        }
        const w1 = addWire(new THREE.IcosahedronGeometry(4.5, 1), 0xc9a84c, .12, -18, 8, -18);
        const w2 = addWire(new THREE.OctahedronGeometry(3, 0), 0x4a9fd4, .13, 16, -8, -14);
        const w3 = addWire(new THREE.TetrahedronGeometry(3.5, 0), 0x9b7fd4, .1, 2, 14, -16);

        /* particles */
        const N = 320;
        const pos = new Float32Array(N * 3);
        const col = new Float32Array(N * 3);
        const pal = [C_GOLD, C_GOLD2, C_TEAL, C_BLUE, C_PURPLE, new THREE.Color(0xffffff)];
        for (let i = 0; i < N; i++) {
          const r = 12 + Math.random() * 26;
          const phi = Math.acos(-1 + 2 * Math.random());
          const th = Math.random() * Math.PI * 2;
          pos[i * 3] = r * Math.sin(phi) * Math.cos(th);
          pos[i * 3 + 1] = r * Math.sin(phi) * Math.sin(th) * .55;
          pos[i * 3 + 2] = r * Math.cos(phi) - 10;
          const c = pal[Math.floor(Math.random() * pal.length)];
          col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
        }
        const partGeo = new THREE.BufferGeometry();
        partGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        partGeo.setAttribute('color', new THREE.BufferAttribute(col, 3));
        const parts = new THREE.Points(partGeo,
          new THREE.PointsMaterial({ size: .11, vertexColors: true, transparent: true, opacity: .5, sizeAttenuation: true })
        );
        scene.add(parts);

        /* lights */
        scene.add(new THREE.AmbientLight(0xffffff, .45));
        const pl1 = new THREE.PointLight(0xc9a84c, 2.8, 70); pl1.position.set(-5, 8, 10); scene.add(pl1);
        const pl2 = new THREE.PointLight(0x4caf82, 1.8, 55); pl2.position.set(10, -6, 8); scene.add(pl2);

        /* mouse */
        let mx = 0, my = 0;
        window.addEventListener('mousemove', e => { mx = (e.clientX / innerWidth - .5) * 2; my = (e.clientY / innerHeight - .5) * 2 });
        window.addEventListener('touchmove', e => {
          if (e.touches.length) {
            mx = (e.touches[0].clientX / innerWidth - .5) * 2;
            my = (e.touches[0].clientY / innerHeight - .5) * 2;
          }
        }, { passive: true });

        let t = 0;
        function animate() {
          requestAnimationFrame(animate);
          t += .006;

          /* camera parallax */
          camera.position.x += (mx * 1.4 - camera.position.x) * .022;
          camera.position.y += (-my * .8 - camera.position.y) * .022;
          camera.lookAt(0, 0, 0);

          /* orb float */
          orbs.forEach(o => {
            const d = o.userData;
            o.position.y = d.oy + Math.sin(t * d.speed + d.phase) * 1.3;
            o.position.x = d.ox + Math.cos(t * d.speed * .65 + d.phase) * .7;
            o.rotation.y += .003;
          });

          /* ring spin */
          t1.rotation.z += .004; t2.rotation.x += .003; t2.rotation.z += .002; t3.rotation.y += .0015;

          /* wire spin */
          w1.rotation.x += .004; w1.rotation.y += .005;
          w2.rotation.x += .003; w2.rotation.z += .006;
          w3.rotation.y += .004; w3.rotation.x += .003;

          /* particles */
          parts.rotation.y += .0007;
          parts.rotation.x = Math.sin(t * .14) * .05;

          /* breathing lights */
          pl1.intensity = 2.4 + Math.sin(t * 1.1) * .8;
          pl2.intensity = 1.4 + Math.cos(t * .85) * .6;

          renderer.render(scene, camera);
        }
        animate();
      } catch (e) {
        /* A decorative canvas is never worth breaking the sign-in form. */
        console.warn('Background scene skipped:', e && e.message);
      }
    })();


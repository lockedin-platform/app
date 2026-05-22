import { animate, createTimeline, stagger } from 'animejs';
import * as THREE from 'three';

const homeState = {
    reducedMotion: false,
    animationFrameId: 0,
    pillIntervalId: 0,
    revealObservers: [],
    strengthHandlers: [],
    scrollHandler: null,
    resizeHandler: null,
    beforeRenderHandler: null,
    beforeUnloadHandler: null,
    scrollElements: null,
    sceneController: null,
    flags: {},
};

export function mountHomeExperience() {
    teardownHomeExperience();

    const hero = document.getElementById('njHero');

    if (!hero) {
        return;
    }

    homeState.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    homeState.flags = {
        signalLabelAnimated: false,
        tickerItemsAnimated: false,
        journeyLabelAnimated: false,
        proofLabelAnimated: false,
        closingLabelAnimated: false,
        impactRingsAnimated: false,
    };

    const payload = readPayload();
    const sceneController = initHeroScene(homeState.reducedMotion);
    homeState.sceneController = sceneController;

    prepareImpactRings();
    initHeroTimeline(hero, homeState.reducedMotion);
    initHeroCounters(hero, homeState.reducedMotion);
    initHeroPill(payload.pillItems, homeState.reducedMotion);
    initRevealObservers(homeState.reducedMotion);
    initScrollStory(homeState.reducedMotion, sceneController);
    initStrengthMagnetism(homeState.reducedMotion);

    homeState.beforeRenderHandler = teardownHomeExperience;
    homeState.beforeUnloadHandler = teardownHomeExperience;

    document.addEventListener('turbo:before-render', homeState.beforeRenderHandler, { once: true });
    window.addEventListener('beforeunload', homeState.beforeUnloadHandler, { once: true });

    startFrameLoop();
}

function readPayload() {
    const payloadElement = document.getElementById('homeExperienceData');

    if (!payloadElement) {
        return { pillItems: [] };
    }

    try {
        return JSON.parse(payloadElement.textContent || '{}');
    } catch {
        return { pillItems: [] };
    }
}

function initHeroTimeline(hero, reducedMotion) {
    if (reducedMotion) {
        return;
    }

    const timeline = createTimeline({
        defaults: {
            duration: 900,
            ease: 'outExpo',
        },
    });

    addIfTarget(timeline, hero.querySelector('.nj-hero-kicker'), {
        opacity: { from: 0 },
        y: { from: '1.2rem' },
    }, 0);

    addIfTarget(timeline, hero.querySelector('.nj-stat-pill'), {
        opacity: { from: 0 },
        y: { from: '1rem' },
    }, 90);

    addIfTarget(timeline, hero.querySelectorAll('.nj-hero-title-line'), {
        opacity: { from: 0 },
        y: { from: '2.5rem' },
        rotateX: { from: '50deg' },
        delay: stagger(140),
    }, 150);

    addIfTarget(timeline, hero.querySelector('.nj-hero-lead'), {
        opacity: { from: 0 },
        y: { from: '1.4rem' },
    }, 280);

    addIfTarget(timeline, hero.querySelector('.nj-hero-actions'), {
        opacity: { from: 0 },
        y: { from: '1rem' },
    }, 360);

    addIfTarget(timeline, hero.querySelector('.nj-hero-meta'), {
        opacity: { from: 0 },
        y: { from: '1rem' },
    }, 430);

    addIfTarget(timeline, hero.querySelector('.nj-hero-stats'), {
        opacity: { from: 0 },
        y: { from: '1.2rem' },
    }, 500);

    addIfTarget(timeline, hero.querySelector('.nj-stage-shell'), {
        opacity: { from: 0 },
    }, 260);

    addIfTarget(timeline, hero.querySelectorAll('.nj-stage-card'), {
        opacity: { from: 0 },
        delay: stagger(120),
    }, 360);

    addIfTarget(timeline, hero.querySelectorAll('.nj-stage-chip, .nj-stage-badge, .nj-stage-beacon'), {
        opacity: { from: 0 },
        y: { from: '1rem' },
        delay: stagger(85),
    }, 420);
}

function addIfTarget(timeline, target, parameters, position) {
    if (!target) {
        return;
    }

    if ('length' in target && target.length === 0) {
        return;
    }

    timeline.add(target, parameters, position);
}

function initHeroCounters(hero, reducedMotion) {
    const heroCounters = hero.querySelectorAll('.nj-hero-stat .stat-num');
    const impactCounters = document.querySelectorAll('.nj-impact-num');

    if (reducedMotion) {
        heroCounters.forEach(setCounterInstant);
        impactCounters.forEach(setCounterInstant);
        return;
    }

    window.setTimeout(() => {
        heroCounters.forEach((counter) => animateCounter(counter, 1600));
    }, 700);
}

function initHeroPill(pillItems, reducedMotion) {
    const pill = document.getElementById('heroPill');
    const pillText = document.getElementById('heroPillText');

    if (homeState.pillIntervalId) {
        window.clearInterval(homeState.pillIntervalId);
        homeState.pillIntervalId = 0;
    }

    if (!pill || !pillText || !Array.isArray(pillItems) || pillItems.length < 2 || reducedMotion) {
        return;
    }

    let currentIndex = 0;

    homeState.pillIntervalId = window.setInterval(() => {
        pill.classList.add('fade-out');

        window.setTimeout(() => {
            currentIndex = (currentIndex + 1) % pillItems.length;
            pillText.textContent = pillItems[currentIndex];
            pill.classList.remove('fade-out');
        }, 220);
    }, 3200);
}

function initRevealObservers(reducedMotion) {
    const revealGroups = [
        { nodes: Array.from(document.querySelectorAll('.nj-audience-card')), staggerMs: 100 },
        { nodes: filterNodes([document.getElementById('njTicker')]), staggerMs: 0 },
        {
            nodes: filterNodes([document.getElementById('impactGrid')]),
            staggerMs: 0,
            onReveal: () => {
                document.querySelectorAll('.nj-impact-num').forEach((counter) => animateCounter(counter, 1800));
                animateImpactRings();
            },
        },
        { nodes: Array.from(document.querySelectorAll('.nj-strength-card')), staggerMs: 80 },
        { nodes: filterNodes([document.getElementById('njClosing')]), staggerMs: 0 },
    ];

    if (reducedMotion || !('IntersectionObserver' in window)) {
        revealGroups.forEach(({ nodes, onReveal }) => {
            nodes.forEach((node) => node.classList.add('revealed'));

            if (onReveal) {
                onReveal();
            }
        });

        return;
    }

    revealGroups.forEach(({ nodes, staggerMs, onReveal }) => {
        if (!nodes.length) {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                observer.unobserve(entry.target);

                const index = nodes.indexOf(entry.target);
                const delay = Math.max(index, 0) * staggerMs;

                window.setTimeout(() => {
                    entry.target.classList.add('revealed');
                }, delay);

                if (onReveal) {
                    onReveal(entry.target);
                }
            });
        }, { threshold: 0.18 });

        homeState.revealObservers.push(observer);
        nodes.forEach((node) => observer.observe(node));
    });
}

function filterNodes(nodes) {
    return nodes.filter(Boolean);
}

function animateCounter(element, duration) {
    if (element.dataset.counterAnimated === 'true') {
        return;
    }

    const target = Number.parseInt(element.dataset.count || '0', 10);
    element.dataset.counterAnimated = 'true';

    if (!target) {
        element.textContent = '0';
        return;
    }

    const valueHolder = { value: 0 };

    animate(valueHolder, {
        value: target,
        duration,
        ease: 'outExpo',
        onUpdate: () => {
            element.textContent = Math.round(valueHolder.value).toLocaleString('fr-FR');
        },
        onComplete: () => {
            element.textContent = target.toLocaleString('fr-FR');
        },
    });
}

function setCounterInstant(element) {
    const target = Number.parseInt(element.dataset.count || '0', 10);
    element.textContent = target.toLocaleString('fr-FR');
    element.dataset.counterAnimated = 'true';
}

function initHeroScene(reducedMotion) {
    const canvas = document.getElementById('heroCanvas');
    const stageShell = document.getElementById('heroStageShell');
    const sceneController = {
        enabled: false,
        stageShell,
        canvas,
        renderer: null,
        scene: null,
        camera: null,
        system: null,
        core: null,
        wireShell: null,
        torusA: null,
        torusB: null,
        network: null,
        networkMeta: null,
        starField: null,
        scrollProgress: 0,
        journeyProgress: 0,
        pulseScale: 1,
        starSpeedMultiplier: 1,
        setScrollProgress(progress) {
            this.scrollProgress = progress;
        },
        setJourneyProgress(progress) {
            this.journeyProgress = progress;
        },
        resetPointer() {},
        resizeScene() {},
        updateFrame() {},
        dispose() {},
    };

    if (!canvas || !stageShell) {
        return sceneController;
    }

    if (reducedMotion || window.innerWidth < 820 || !supportsWebGL()) {
        canvas.remove();
        return sceneController;
    }

    const renderer = new THREE.WebGLRenderer({
        canvas,
        alpha: true,
        antialias: true,
        powerPreference: 'high-performance',
    });

    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.8));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.08;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
    camera.position.set(0, 0, 7.5);

    scene.add(new THREE.AmbientLight(0xffffff, 0.45));

    const keyLight = new THREE.PointLight(0x7c3aed, 14, 20, 2);
    keyLight.position.set(2.8, 2.4, 4.2);
    scene.add(keyLight);

    const fillLight = new THREE.PointLight(0x67e8f9, 11, 18, 2);
    fillLight.position.set(-3.6, -1.8, 3.4);
    scene.add(fillLight);

    const rimLight = new THREE.PointLight(0xf59e0b, 10, 16, 2);
    rimLight.position.set(1.6, -3.2, 3.8);
    scene.add(rimLight);

    const system = new THREE.Group();
    scene.add(system);

    const core = new THREE.Mesh(
        new THREE.IcosahedronGeometry(1.15, 1),
        new THREE.MeshStandardMaterial({
            color: 0x8ad5ff,
            emissive: 0x345cff,
            emissiveIntensity: 1.25,
            metalness: 0.35,
            roughness: 0.1,
            flatShading: true,
            transparent: true,
            opacity: 0.95,
        }),
    );
    system.add(core);

    const wireShell = new THREE.Mesh(
        new THREE.IcosahedronGeometry(1.8, 1),
        new THREE.MeshBasicMaterial({
            color: 0x9b8cff,
            wireframe: true,
            transparent: true,
            opacity: 0.34,
        }),
    );
    system.add(wireShell);

    const torusA = new THREE.Mesh(
        new THREE.TorusGeometry(2.35, 0.03, 12, 160),
        new THREE.MeshBasicMaterial({ color: 0xf59e0b, transparent: true, opacity: 0.52 }),
    );
    torusA.rotation.x = 0.4;
    system.add(torusA);

    const torusB = new THREE.Mesh(
        new THREE.TorusGeometry(2.05, 0.025, 12, 160),
        new THREE.MeshBasicMaterial({ color: 0x67e8f9, transparent: true, opacity: 0.46 }),
    );
    torusB.rotation.x = 0.42;
    torusB.rotation.y = Math.PI / 2.1;
    torusB.rotation.z = 0.3;
    system.add(torusB);

    const network = buildNetworkMesh();
    network.scale.setScalar(1.35);
    system.add(network);

    const starField = buildStarField();
    starField.userData.speedMultiplier = 1;
    scene.add(starField);

    const pointer = {
        x: 0,
        y: 0,
        currentX: 0,
        currentY: 0,
    };

    const updateStagePointer = (event) => {
        const rect = stageShell.getBoundingClientRect();
        const ratioX = (event.clientX - rect.left) / rect.width;
        const ratioY = (event.clientY - rect.top) / rect.height;
        const centeredX = THREE.MathUtils.clamp((ratioX - 0.5) * 2, -1, 1);
        const centeredY = THREE.MathUtils.clamp((ratioY - 0.5) * 2, -1, 1);

        pointer.x = centeredX;
        pointer.y = centeredY;

        stageShell.style.setProperty('--stage-tilt-x', `${(centeredX * 9).toFixed(2)}deg`);
        stageShell.style.setProperty('--stage-tilt-y', `${(-centeredY * 7).toFixed(2)}deg`);
        stageShell.style.setProperty('--stage-pointer-x', `${(ratioX * 100).toFixed(2)}%`);
        stageShell.style.setProperty('--stage-pointer-y', `${(ratioY * 100).toFixed(2)}%`);
    };

    const resetStagePointer = () => {
        pointer.x = 0;
        pointer.y = 0;
        stageShell.style.setProperty('--stage-tilt-x', '0deg');
        stageShell.style.setProperty('--stage-tilt-y', '0deg');
        stageShell.style.setProperty('--stage-pointer-x', '50%');
        stageShell.style.setProperty('--stage-pointer-y', '50%');
    };

    stageShell.addEventListener('pointermove', updateStagePointer);
    stageShell.addEventListener('pointerleave', resetStagePointer);

    const resizeScene = () => {
        const rect = stageShell.getBoundingClientRect();
        const width = Math.max(rect.width, 1);
        const height = Math.max(rect.height, 1);

        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };

    resizeScene();
    canvas.classList.add('loaded');

    sceneController.enabled = true;
    sceneController.renderer = renderer;
    sceneController.scene = scene;
    sceneController.camera = camera;
    sceneController.system = system;
    sceneController.core = core;
    sceneController.wireShell = wireShell;
    sceneController.torusA = torusA;
    sceneController.torusB = torusB;
    sceneController.network = network;
    sceneController.networkMeta = network.userData;
    sceneController.starField = starField;
    sceneController.resetPointer = resetStagePointer;
    sceneController.resizeScene = resizeScene;
    sceneController.updateFrame = (elapsed) => {
        const pulsePhase = (Math.sin((elapsed / 3) * Math.PI * 2) + 1) * 0.5;
        sceneController.pulseScale = THREE.MathUtils.lerp(0.97, 1.03, pulsePhase);

        pointer.currentX += (pointer.x - pointer.currentX) * 0.05;
        pointer.currentY += (pointer.y - pointer.currentY) * 0.05;

        core.rotation.x = elapsed * 0.28;
        core.rotation.y = elapsed * 0.38;
        wireShell.rotation.x = elapsed * -0.17;
        wireShell.rotation.y = -(core.rotation.x * 0.5);
        torusA.rotation.x = THREE.MathUtils.lerp(0.4, 1.2, sceneController.scrollProgress);
        torusA.rotation.z = elapsed * 0.32;
        torusB.rotation.x = 0.42 + (elapsed * 0.27);
        torusB.rotation.y = Math.PI / 2.1;
        torusB.rotation.z = THREE.MathUtils.lerp(0.3, -0.8, sceneController.scrollProgress);
        network.rotation.y = elapsed * -0.18;
        network.rotation.x = Math.sin(elapsed * 0.45) * 0.18;

        lerpSceneToNetworkState(sceneController, sceneController.journeyProgress);

        system.rotation.x = Math.sin(elapsed * 0.35) * 0.14 + (pointer.currentY * 0.3) - (sceneController.scrollProgress * 0.24);
        system.rotation.y = (elapsed * 0.16) + (pointer.currentX * 0.36) + (sceneController.scrollProgress * 0.42);
        system.position.y = sceneController.scrollProgress * 0.42;
        system.position.z = sceneController.scrollProgress * 0.3;
        system.scale.setScalar(1 + (sceneController.scrollProgress * 0.24));

        starField.rotation.y = elapsed * (0.025 * sceneController.starSpeedMultiplier);
        starField.rotation.x = elapsed * 0.01;
        starField.material.opacity = THREE.MathUtils.lerp(0.55 - (sceneController.scrollProgress * 0.18), 0.34, sceneController.journeyProgress);
        wireShell.material.opacity = THREE.MathUtils.lerp(0.34 + (sceneController.scrollProgress * 0.1), 0.62, sceneController.journeyProgress);
        torusA.material.opacity = THREE.MathUtils.lerp(0.52 - (sceneController.scrollProgress * 0.12), 0.14, sceneController.journeyProgress);
        torusB.material.opacity = THREE.MathUtils.lerp(0.46 + (sceneController.scrollProgress * 0.08), 0.2, sceneController.journeyProgress);

        camera.position.x = pointer.currentX * 1.15;
        camera.position.y = (-pointer.currentY * 0.86) + (sceneController.scrollProgress * 0.34);
        camera.position.z = 7.5 - (sceneController.scrollProgress * 1.15) - (sceneController.journeyProgress * 0.4);
        camera.lookAt(0, 0, 0);

        renderer.render(scene, camera);
    };
    sceneController.dispose = () => {
        resetStagePointer();
        stageShell.classList.remove('is-journey-fixed');
        stageShell.style.removeProperty('--journey-scene-progress');
        stageShell.removeEventListener('pointermove', updateStagePointer);
        stageShell.removeEventListener('pointerleave', resetStagePointer);
        renderer.dispose();

        scene.traverse((object) => {
            if (object.geometry) {
                object.geometry.dispose();
            }

            if (object.material) {
                if (Array.isArray(object.material)) {
                    object.material.forEach((material) => material.dispose());
                } else {
                    object.material.dispose();
                }
            }
        });
    };

    return sceneController;
}

function initScrollStory(reducedMotion, sceneController) {
    const scrollElements = {
        hero: document.getElementById('njHero'),
        stageShell: document.getElementById('heroStageShell'),
        signalSection: document.getElementById('signalChapter'),
        signalLabel: document.getElementById('signalLabel'),
        ticker: document.getElementById('njTicker'),
        tickerItems: Array.from(document.querySelectorAll('.nj-ticker-item')),
        journeyWrapper: document.getElementById('journeyWrapper'),
        journeyTrack: document.getElementById('journeyTrack'),
        journeyLabel: document.getElementById('journeyLabel'),
        journeySlides: Array.from(document.querySelectorAll('[data-journey-slide]')),
        journeyDots: Array.from(document.querySelectorAll('[data-journey-dot]')),
        proofSection: document.getElementById('proofChapter'),
        proofLabel: document.getElementById('proofLabel'),
        impactGrid: document.getElementById('impactGrid'),
        strengthsGrid: document.getElementById('strengthsGrid'),
        closing: document.getElementById('njClosing'),
        closingLabel: document.getElementById('closingLabel'),
    };

    homeState.scrollElements = scrollElements;

    const onScroll = () => {
        homeState.lastKnownScrollY = window.scrollY;
    };

    const onResize = () => {
        homeState.viewportWidth = window.innerWidth;

        if (sceneController.resizeScene) {
            sceneController.resizeScene();
        }
    };

    homeState.scrollHandler = onScroll;
    homeState.resizeHandler = onResize;

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onResize, { passive: true });

    onScroll();
    onResize();
}

function getStickyProgress(element) {
    const start = window.scrollY + element.getBoundingClientRect().top;
    const distance = Math.max(element.offsetHeight - window.innerHeight, 1);

    return clamp((window.scrollY - start) / distance, 0, 1);
}

function getRevealProgress(element, startRatio, endRatio) {
    if (!element) {
        return 0;
    }

    const rect = element.getBoundingClientRect();
    const viewportHeight = Math.max(window.innerHeight, 1);
    const start = viewportHeight * startRatio;
    const end = viewportHeight * endRatio;

    return clamp((start - rect.top) / Math.max(start - end, 1), 0, 1);
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function buildNetworkMesh() {
    const networkGroup = new THREE.Group();
    const points = [];
    const connections = [];
    const lineVertices = [];
    const nodes = [];

    for (let index = 0; index < 22; index += 1) {
        const radius = 1.8 + (Math.random() * 1.2);
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos((Math.random() * 2) - 1);
        const point = new THREE.Vector3(
            radius * Math.sin(phi) * Math.cos(theta),
            radius * Math.sin(phi) * Math.sin(theta),
            radius * Math.cos(phi),
        );

        points.push(point);
    }

    for (let sourceIndex = 0; sourceIndex < points.length; sourceIndex += 1) {
        for (let targetIndex = sourceIndex + 1; targetIndex < points.length; targetIndex += 1) {
            if (points[sourceIndex].distanceTo(points[targetIndex]) > 2.25) {
                continue;
            }

            connections.push([sourceIndex, targetIndex]);
            lineVertices.push(
                points[sourceIndex].x,
                points[sourceIndex].y,
                points[sourceIndex].z,
                points[targetIndex].x,
                points[targetIndex].y,
                points[targetIndex].z,
            );
        }
    }

    const lineGeometry = new THREE.BufferGeometry();
    lineGeometry.setAttribute('position', new THREE.Float32BufferAttribute(lineVertices, 3));
    const lineMesh = new THREE.LineSegments(
        lineGeometry,
        new THREE.LineBasicMaterial({ color: 0x76c4ff, transparent: true, opacity: 0.15 }),
    );
    networkGroup.add(lineMesh);

    points.forEach((point, index) => {
        const baseOpacity = index % 4 === 0 ? 0.82 : 0.68;
        const node = new THREE.Mesh(
            new THREE.SphereGeometry(index % 4 === 0 ? 0.07 : 0.045, 10, 10),
            new THREE.MeshBasicMaterial({
                color: index % 4 === 0 ? 0xf59e0b : 0xffffff,
                transparent: true,
                opacity: baseOpacity,
            }),
        );

        node.userData.baseOpacity = baseOpacity;
        node.position.copy(point);
        nodes.push(node);
        networkGroup.add(node);
    });

    networkGroup.userData = {
        nodes,
        basePoints: points.map((point) => point.clone()),
        connections,
        lineMesh,
        lineGeometry,
    };

    return networkGroup;
}

function buildStarField() {
    const positions = [];

    for (let index = 0; index < 1200; index += 1) {
        const radius = 3.4 + (Math.random() * 5.2);
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos((Math.random() * 2) - 1);

        positions.push(
            radius * Math.sin(phi) * Math.cos(theta),
            radius * Math.sin(phi) * Math.sin(theta),
            radius * Math.cos(phi),
        );
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));

    return new THREE.Points(
        geometry,
        new THREE.PointsMaterial({
            color: 0xb8e8ff,
            size: 0.035,
            transparent: true,
            opacity: 0.55,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        }),
    );
}

function supportsWebGL() {
    const testCanvas = document.createElement('canvas');

    return Boolean(
        testCanvas.getContext('webgl') || testCanvas.getContext('experimental-webgl'),
    );
}

function startFrameLoop() {
    const tick = (time) => {
        homeState.animationFrameId = window.requestAnimationFrame(tick);
        updateScrollStoryFrame(homeState.reducedMotion, homeState.sceneController);

        if (homeState.sceneController && homeState.sceneController.updateFrame) {
            homeState.sceneController.updateFrame(time * 0.001);
        }
    };

    homeState.animationFrameId = window.requestAnimationFrame(tick);
}

function updateScrollStoryFrame(reducedMotion, sceneController) {
    const elements = homeState.scrollElements;

    if (!elements || !elements.hero) {
        return;
    }

    const heroProgress = getStickyProgress(elements.hero);
    const heroExitProgress = clamp((heroProgress - 0.7) / 0.3, 0, 1);

    elements.hero.style.setProperty('--hero-progress', heroProgress.toFixed(4));
    elements.hero.style.setProperty('--hero-exit-progress', heroExitProgress.toFixed(4));

    if (elements.stageShell) {
        elements.stageShell.style.setProperty('--stage-story-y', `${(-heroProgress * 28).toFixed(2)}px`);
        elements.stageShell.style.setProperty('--stage-story-scale', `${(1 + (heroProgress * 0.18)).toFixed(3)}`);
        elements.stageShell.style.setProperty('--stage-story-rotate-x', `${(-heroProgress * 9).toFixed(2)}deg`);
        elements.stageShell.style.setProperty('--stage-story-rotate-y', `${(heroProgress * 7).toFixed(2)}deg`);
    }

    sceneController.setScrollProgress(heroProgress);

    const signalProgress = elements.signalSection ? getRevealProgress(elements.signalSection, 0.92, 0.18) : 0;

    if (elements.ticker) {
        elements.ticker.style.setProperty('--ticker-progress', signalProgress.toFixed(4));
    }

    if (signalProgress >= 0.3) {
        revealChapterLabel('signalLabelAnimated', elements.signalLabel);
    }

    if (signalProgress >= 0.2) {
        animateTickerItems(elements.tickerItems);
    }

    const desktopJourney = !reducedMotion && window.innerWidth >= 820;
    const journeyProgress = desktopJourney && elements.journeyWrapper ? getHorizontalProgress(elements.journeyWrapper) : 0;
    const journeyActive = desktopJourney && journeyProgress > 0.01 && journeyProgress < 0.995;

    if (elements.journeyTrack) {
        elements.journeyTrack.style.setProperty('--journey-translate', desktopJourney ? `${(-journeyProgress * 500).toFixed(4)}vw` : '0vw');
    }

    if (elements.stageShell) {
        elements.stageShell.classList.toggle('is-journey-fixed', journeyActive);
        elements.stageShell.style.setProperty('--journey-scene-progress', desktopJourney ? journeyProgress.toFixed(4) : '0');
    }

    if (journeyActive) {
        sceneController.resetPointer();
    }

    sceneController.setJourneyProgress(desktopJourney ? journeyProgress : 0);
    setJourneySlideState(elements.journeySlides, elements.journeyDots, desktopJourney ? journeyProgress : 0, !desktopJourney);

    if ((desktopJourney && journeyProgress >= 0.06) || (!desktopJourney && elements.journeyWrapper && getRevealProgress(elements.journeyWrapper, 0.92, 0.22) >= 0.2)) {
        revealChapterLabel('journeyLabelAnimated', elements.journeyLabel);
    }

    const impactProgress = elements.impactGrid ? getRevealProgress(elements.impactGrid, 0.88, 0.18) : 0;

    if (elements.impactGrid) {
        elements.impactGrid.style.setProperty('--impact-progress', impactProgress.toFixed(4));
    }

    if (elements.proofSection && getRevealProgress(elements.proofSection, 0.92, 0.2) >= 0.18) {
        revealChapterLabel('proofLabelAnimated', elements.proofLabel);
    }

    if (elements.strengthsGrid) {
        const strengthsProgress = getRevealProgress(elements.strengthsGrid, 0.9, 0.18);

        document.querySelectorAll('.nj-strength-card').forEach((card, index) => {
            const direction = index % 2 === 0 ? -1 : 1;
            const depth = 1 - strengthsProgress;

            card.style.setProperty('--story-shift', `${(depth * (18 + (index * 4))).toFixed(2)}px`);
            card.style.setProperty('--story-tilt', `${(direction * depth * 5).toFixed(2)}deg`);
        });
    }

    if (elements.closing) {
        const closingProgress = getRevealProgress(elements.closing, 0.88, 0.2);
        elements.closing.style.setProperty('--closing-progress', closingProgress.toFixed(4));

        if (closingProgress >= 0.18) {
            revealChapterLabel('closingLabelAnimated', elements.closingLabel);
        }
    }
}

function getHorizontalProgress(element) {
    const start = window.scrollY + element.getBoundingClientRect().top;
    const distance = Math.max(element.offsetHeight - window.innerHeight, 1);

    return clamp((window.scrollY - start) / distance, 0, 1);
}

function setJourneySlideState(slides, dots, progress, mobileStack) {
    if (!slides.length) {
        return;
    }

    if (mobileStack) {
        slides.forEach((slide) => {
            slide.style.setProperty('--slide-active', '1');
        });

        dots.forEach((dot, index) => {
            dot.style.setProperty('--dot-active', index === 0 ? '1' : '0');
        });

        return;
    }

    const centeredIndex = progress * (slides.length - 1);

    slides.forEach((slide, index) => {
        const active = clamp(1 - Math.abs(centeredIndex - index), 0, 1);
        slide.style.setProperty('--slide-active', active.toFixed(4));
    });

    dots.forEach((dot, index) => {
        const active = clamp(1 - Math.abs(centeredIndex - index), 0, 1);
        dot.style.setProperty('--dot-active', active.toFixed(4));
    });
}

function revealChapterLabel(flagKey, labelElement) {
    if (!labelElement || homeState.flags[flagKey]) {
        return;
    }

    homeState.flags[flagKey] = true;
    labelElement.classList.add('is-visible');

    if (homeState.reducedMotion) {
        return;
    }

    const labelTimeline = createTimeline({
        defaults: {
            duration: 500,
            ease: 'outExpo',
        },
    });

    labelTimeline.add(labelElement, {
        opacity: { from: 0 },
        x: { from: '-20px' },
    }, 0);
}

function animateTickerItems(items) {
    if (!items.length || homeState.flags.tickerItemsAnimated) {
        return;
    }

    homeState.flags.tickerItemsAnimated = true;

    if (homeState.reducedMotion) {
        items.forEach((item) => {
            item.style.opacity = '1';
            item.style.transform = 'none';
        });

        return;
    }

    const tickerTimeline = createTimeline({
        defaults: {
            duration: 600,
            ease: 'outExpo',
        },
    });

    tickerTimeline.add(items, {
        opacity: { from: 0 },
        y: { from: '12px' },
        delay: stagger(40),
    }, 0);
}

function animateImpactRings() {
    if (homeState.flags.impactRingsAnimated) {
        return;
    }

    homeState.flags.impactRingsAnimated = true;

    document.querySelectorAll('.nj-impact-item').forEach((card) => {
        card.classList.add('is-ring-active');
    });
}

function prepareImpactRings() {
    document.querySelectorAll('.nj-ring-fill').forEach((ring) => {
        const radius = Number.parseFloat(ring.getAttribute('r') || '34');
        const circumference = 2 * Math.PI * radius;

        ring.style.strokeDasharray = `${circumference.toFixed(3)}`;
        ring.style.strokeDashoffset = `${circumference.toFixed(3)}`;
    });
}

function initStrengthMagnetism(reducedMotion) {
    if (reducedMotion || !window.matchMedia('(pointer:fine)').matches) {
        return;
    }

    const strengthCards = Array.from(document.querySelectorAll('.nj-strength-card'));

    strengthCards.forEach((card) => {
        const onMouseMove = (event) => {
            const rect = card.getBoundingClientRect();
            const ratioX = ((event.clientX - rect.left) / rect.width) - 0.5;
            const ratioY = ((event.clientY - rect.top) / rect.height) - 0.5;

            card.classList.add('is-magnetic');
            card.classList.remove('is-magnetic-reset');
            card.style.setProperty('--magnetic-x', `${(ratioX * 16).toFixed(2)}px`);
            card.style.setProperty('--magnetic-y', `${(ratioY * 16).toFixed(2)}px`);
            card.style.setProperty('--magnetic-rotate-x', `${(-ratioY * 6).toFixed(2)}deg`);
            card.style.setProperty('--magnetic-rotate-y', `${(ratioX * 6).toFixed(2)}deg`);
        };

        const onMouseLeave = () => {
            card.classList.remove('is-magnetic');
            card.classList.add('is-magnetic-reset');
            card.style.setProperty('--magnetic-x', '0px');
            card.style.setProperty('--magnetic-y', '0px');
            card.style.setProperty('--magnetic-rotate-x', '0deg');
            card.style.setProperty('--magnetic-rotate-y', '0deg');
        };

        card.addEventListener('mousemove', onMouseMove);
        card.addEventListener('mouseleave', onMouseLeave);

        homeState.strengthHandlers.push({
            card,
            onMouseMove,
            onMouseLeave,
        });
    });
}

function lerpSceneToNetworkState(sceneController, progress) {
    if (!sceneController.enabled || !sceneController.networkMeta) {
        return;
    }

    const normalizedProgress = clamp(progress, 0, 1);
    const expansion = 1 + (normalizedProgress * 1.5);
    const { core, wireShell, networkMeta, starField } = sceneController;
    const positions = networkMeta.lineGeometry.getAttribute('position');

    core.scale.setScalar(THREE.MathUtils.lerp(sceneController.pulseScale, 0, normalizedProgress));
    core.material.opacity = THREE.MathUtils.lerp(0.95, 0, normalizedProgress);
    wireShell.scale.setScalar(THREE.MathUtils.lerp(1, 2.5, normalizedProgress));

    networkMeta.nodes.forEach((node, index) => {
        const basePoint = networkMeta.basePoints[index];
        node.position.set(
            basePoint.x * expansion,
            basePoint.y * expansion,
            basePoint.z * expansion,
        );
        node.material.opacity = THREE.MathUtils.lerp(node.userData.baseOpacity, 0.92, normalizedProgress);
    });

    networkMeta.connections.forEach(([sourceIndex, targetIndex], connectionIndex) => {
        const sourcePoint = networkMeta.basePoints[sourceIndex];
        const targetPoint = networkMeta.basePoints[targetIndex];
        const offset = connectionIndex * 6;

        positions.array[offset + 0] = sourcePoint.x * expansion;
        positions.array[offset + 1] = sourcePoint.y * expansion;
        positions.array[offset + 2] = sourcePoint.z * expansion;
        positions.array[offset + 3] = targetPoint.x * expansion;
        positions.array[offset + 4] = targetPoint.y * expansion;
        positions.array[offset + 5] = targetPoint.z * expansion;
    });

    positions.needsUpdate = true;
    networkMeta.lineMesh.material.opacity = THREE.MathUtils.lerp(0.15, 0.6, normalizedProgress);
    sceneController.starSpeedMultiplier = THREE.MathUtils.lerp(1, 3, normalizedProgress);
    starField.userData.speedMultiplier = sceneController.starSpeedMultiplier;
}

function teardownHomeExperience() {
    if (homeState.animationFrameId) {
        window.cancelAnimationFrame(homeState.animationFrameId);
        homeState.animationFrameId = 0;
    }

    if (homeState.pillIntervalId) {
        window.clearInterval(homeState.pillIntervalId);
        homeState.pillIntervalId = 0;
    }

    homeState.revealObservers.forEach((observer) => observer.disconnect());
    homeState.revealObservers = [];

    if (homeState.scrollHandler) {
        window.removeEventListener('scroll', homeState.scrollHandler);
        homeState.scrollHandler = null;
    }

    if (homeState.resizeHandler) {
        window.removeEventListener('resize', homeState.resizeHandler);
        homeState.resizeHandler = null;
    }

    homeState.strengthHandlers.forEach(({ card, onMouseMove, onMouseLeave }) => {
        card.removeEventListener('mousemove', onMouseMove);
        card.removeEventListener('mouseleave', onMouseLeave);
    });
    homeState.strengthHandlers = [];

    if (homeState.beforeRenderHandler) {
        document.removeEventListener('turbo:before-render', homeState.beforeRenderHandler);
        homeState.beforeRenderHandler = null;
    }

    if (homeState.beforeUnloadHandler) {
        window.removeEventListener('beforeunload', homeState.beforeUnloadHandler);
        homeState.beforeUnloadHandler = null;
    }

    if (homeState.sceneController && homeState.sceneController.dispose) {
        homeState.sceneController.dispose();
    }

    homeState.sceneController = null;
    homeState.scrollElements = null;
    homeState.flags = {};
}

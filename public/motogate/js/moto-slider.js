/**
 * MotoGate Hero Motorcycle Auto-Slider & Responsive Video Loader
 * Smooth 3-second cycle with synchronized circular orbit motion (curve physics).
 */
(function() {
    'use strict';

    function initLoaderControl() {
        const loadingArea = document.querySelector('.loading-area');
        if (!loadingArea) return;

        let dismissed = false;

        function dismissLoader() {
            if (dismissed) return;
            dismissed = true;
            loadingArea.style.pointerEvents = 'none';

            if (window.jQuery) {
                window.jQuery(loadingArea).fadeOut(600, function() {
                    try {
                        loadingArea.remove();
                    } catch(e) {
                        loadingArea.style.display = 'none';
                    }
                });
            } else {
                loadingArea.style.opacity = '0';
                loadingArea.style.transition = 'opacity 0.6s ease';
                setTimeout(() => {
                    try {
                        loadingArea.remove();
                    } catch(e) {
                        loadingArea.style.display = 'none';
                    }
                }, 600);
            }
        }

        const video = loadingArea.querySelector('video');
        if (video) {
            // Select mobile video source on small viewports if needed
            if (window.innerWidth <= 768) {
                const mobileSource = video.querySelector('source[media*="max-width"]');
                if (mobileSource && video.currentSrc !== mobileSource.src) {
                    video.src = mobileSource.src;
                }
            }

            // Play complete video to the end
            video.addEventListener('ended', function() {
                setTimeout(dismissLoader, 250);
            });

            video.play().catch(function() {
                setTimeout(dismissLoader, 2500);
            });
        } else {
            setTimeout(dismissLoader, 2500);
        }

        // Safety fallback timer
        setTimeout(dismissLoader, 4000);
    }

    function initMotoSlider() {
        const slider = document.getElementById('twmMotoSlider');
        if (!slider) return;

        const slides = Array.from(slider.querySelectorAll('.twm-moto-slide'));
        if (slides.length <= 1) return;

        let currentIndex = 0;
        const AUTOPLAY_DELAY = 3000; // 3 seconds

        // Initialize first slide as active, others clean
        slides.forEach((slide, idx) => {
            if (idx === 0) {
                slide.classList.add('active');
            } else {
                slide.classList.remove('active', 'outgoing');
            }
        });

        function nextSlide() {
            const currentSlide = slides[currentIndex];
            currentIndex = (currentIndex + 1) % slides.length;
            const nextSlide = slides[currentIndex];

            // 1. Send current slide down to bottom-left (outgoing)
            currentSlide.classList.remove('active');
            currentSlide.classList.add('outgoing');

            // 2. Bring next slide in from right to center (active)
            nextSlide.classList.remove('outgoing');
            nextSlide.classList.add('active');

            // 3. After transition finishes (1.3s), reset old slide to clean right-side standby
            setTimeout(() => {
                currentSlide.classList.remove('outgoing');
            }, 1300);
        }

        setInterval(nextSlide, AUTOPLAY_DELAY);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initLoaderControl();
            initMotoSlider();
        });
    } else {
        initLoaderControl();
        initMotoSlider();
    }
})();

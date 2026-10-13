/*
 * Machine detail page: main gallery + thumbnails, colour swatches, and a
 * full-screen viewer (Swiper zoom: pinch / double-tap / wheel, arrows,
 * swipe, keyboard, Esc). Swiper ships with the design (swiper-bundle).
 */
(function () {
    'use strict';

    if (typeof Swiper === 'undefined') return;

    var mainEl = document.querySelector('.mg-gallery__main');
    if (!mainEl) return;

    var thumbsEl = document.querySelector('.mg-gallery__thumbs');
    var currentEl = document.querySelector('[data-gallery-current]');
    var swatches = [].slice.call(document.querySelectorAll('.mg-swatch'));
    var colorName = document.querySelector('[data-color-name]');

    var thumbs = thumbsEl ? new Swiper(thumbsEl, {
        slidesPerView: 'auto',
        spaceBetween: 10,
        watchSlidesProgress: true,
        slideToClickedSlide: true
    }) : null;

    var gallery = new Swiper(mainEl, {
        spaceBetween: 12,
        speed: 450,
        keyboard: { enabled: true, onlyInViewport: true },
        a11y: { enabled: true },
        navigation: {
            prevEl: mainEl.querySelector('.mg-gallery__nav--prev'),
            nextEl: mainEl.querySelector('.mg-gallery__nav--next')
        },
        thumbs: thumbs ? { swiper: thumbs } : undefined
    });

    function markVariant(variant) {
        swatches.forEach(function (s) {
            var on = parseInt(s.getAttribute('data-variant'), 10) === variant;
            s.classList.toggle('is-active', on);
            s.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (on && colorName) colorName.textContent = s.getAttribute('data-name') || '';
        });
    }

    gallery.on('slideChange', function () {
        if (currentEl) currentEl.textContent = gallery.activeIndex + 1;
        var slide = gallery.slides[gallery.activeIndex];
        if (slide) markVariant(parseInt(slide.getAttribute('data-variant'), 10) || 0);
    });

    swatches.forEach(function (s) {
        s.addEventListener('click', function () {
            markVariant(parseInt(s.getAttribute('data-variant'), 10));
            gallery.slideTo(parseInt(s.getAttribute('data-slide'), 10) || 0);
        });
    });

    /* ---------- Full-screen viewer ---------- */
    var box = document.getElementById('mgLightbox');
    if (!box) return;

    var viewer = null;
    var lastFocus = null;
    var counter = box.querySelector('.mg-lightbox__counter');
    var caption = box.querySelector('.mg-lightbox__caption');
    var total = box.querySelectorAll('.swiper-slide').length;

    // The live-chat bubble would sit on top of the viewer.
    function chat(method) {
        try { if (window.Tawk_API && typeof window.Tawk_API[method] === 'function') window.Tawk_API[method](); } catch (e) {}
    }

    function loadAround(index) {
        // Full-size images load only when the viewer needs them.
        [index - 1, index, index + 1].forEach(function (i) {
            var img = box.querySelectorAll('.swiper-slide img')[i];
            if (img && !img.getAttribute('src')) img.setAttribute('src', img.getAttribute('data-src'));
        });
    }

    function update() {
        var i = viewer.activeIndex;
        loadAround(i);
        if (counter) counter.textContent = (i + 1) + ' / ' + total;
        var img = box.querySelectorAll('.swiper-slide img')[i];
        if (caption) caption.textContent = img ? img.getAttribute('alt') : '';
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
        if (e.key === 'Tab') {
            // Keep focus inside the dialog.
            var focusables = [].slice.call(box.querySelectorAll('button:not([disabled])'));
            if (!focusables.length) return;
            var first = focusables[0], last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    function open(index) {
        lastFocus = document.activeElement;
        box.hidden = false;
        document.documentElement.classList.add('mg-lightbox-open');
        chat('hideWidget');
        loadAround(index);

        if (!viewer) {
            viewer = new Swiper(box.querySelector('.mg-lightbox__swiper'), {
                initialSlide: index,
                spaceBetween: 24,
                zoom: { maxRatio: 3, toggle: true },
                keyboard: { enabled: true },
                a11y: { enabled: true },
                navigation: {
                    prevEl: box.querySelector('.mg-lightbox__nav--prev'),
                    nextEl: box.querySelector('.mg-lightbox__nav--next')
                },
                on: { slideChange: function () { if (viewer) update(); } }
            });
        } else {
            viewer.update();
            viewer.slideTo(index, 0);
        }

        update();
        requestAnimationFrame(function () { box.classList.add('is-visible'); });
        box.querySelector('.mg-lightbox__close').focus();
        document.addEventListener('keydown', onKey);
    }

    function close() {
        if (box.hidden) return;
        if (viewer && viewer.zoom) viewer.zoom.out();
        box.classList.remove('is-visible');
        document.documentElement.classList.remove('mg-lightbox-open');
        chat('showWidget');
        document.removeEventListener('keydown', onKey);
        setTimeout(function () { box.hidden = true; }, 200);
        // Bring the page gallery to the photo the visitor stopped on, and
        // return focus to that photo (the one that opened it may be off-screen).
        if (viewer) gallery.slideTo(viewer.activeIndex, 0);
        var current = mainEl.querySelectorAll('.mg-gallery__open')[gallery.activeIndex];
        var target = current || lastFocus;
        if (target && target.focus) target.focus({ preventScroll: true });
    }

    mainEl.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-lightbox-index]');
        if (trigger) open(parseInt(trigger.getAttribute('data-lightbox-index'), 10) || 0);
    });

    box.querySelector('.mg-lightbox__close').addEventListener('click', close);

    // A tap on the dark backdrop (not the photo or the controls) closes -
    // but not the end of a swipe, which also fires a click.
    var down = null;
    box.addEventListener('pointerdown', function (e) { down = { x: e.clientX, y: e.clientY }; });
    box.addEventListener('click', function (e) {
        var moved = down && (Math.abs(e.clientX - down.x) > 6 || Math.abs(e.clientY - down.y) > 6);
        var onBackdrop = e.target === box || e.target.classList.contains('swiper-zoom-container') || e.target.classList.contains('swiper-slide');
        if (onBackdrop && !moved) close();
    });
})();

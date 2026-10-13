/*
 * Hero bikes: PNGs carry transparent padding under the wheels (the design's
 * have 12%, uploads vary). Measure it once per image and pull the image down
 * by that much, so the wheels - not the PNG's edge - sit on the stage line.
 */
(function () {
    'use strict';

    var bikes = [].slice.call(document.querySelectorAll('img[data-hero-bike]'));
    if (!bikes.length) return;

    function bottomPadding(img) {
        try {
            var w = 120, h = Math.max(1, Math.round(img.naturalHeight / img.naturalWidth * w));
            var canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(img, 0, 0, w, h);
            var data = ctx.getImageData(0, 0, w, h).data;
            for (var y = h - 1; y >= 0; y--) {
                for (var x = 0; x < w; x++) {
                    if (data[(y * w + x) * 4 + 3] > 24) return (h - 1 - y) / h;
                }
            }
        } catch (e) { /* cross-origin image: leave as is */ }
        return 0;
    }

    function place(img) {
        var pad = img._heroPad || 0;
        img.style.marginBottom = pad ? (-pad * img.offsetHeight) + 'px' : '';
    }

    function measure(img) {
        img._heroPad = bottomPadding(img);
        img.setAttribute('data-hero-pad', img._heroPad.toFixed(3));
        place(img);
    }

    // decode() resolves once pixels are readable - measuring earlier reads 0.
    function whenReady(img) {
        var go = function () {
            (img.decode ? img.decode() : Promise.resolve()).then(function () { measure(img); }, function () { measure(img); });
        };
        if (img.complete && img.naturalWidth) go();
        else img.addEventListener('load', go, { once: true });
    }

    bikes.forEach(whenReady);

    var timer;
    window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { bikes.forEach(place); }, 120);
    });
})();

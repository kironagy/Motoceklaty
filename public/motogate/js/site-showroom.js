/* Showroom: brand chips are real links now (server-side filter + pagination);
 * this only expands/collapses the extra brands on small screens. */
jQuery(function ($) {
    var $bar = $('.twm-creative-filter-bar');
    var $btn = $('#toggleBrandsBtn');
    if (!$bar.length || !$btn.length) return;

    $btn.on('click', function (e) {
        e.preventDefault();
        var $container = $('#brandFilterContainer').toggleClass('is-expanded');
        var open = $container.hasClass('is-expanded');
        $btn.attr('aria-expanded', open ? 'true' : 'false')
            .empty()
            .append($('<span>').text(open ? $bar.data('less-label') : $bar.data('more-label')))
            .append(' <i class="fa-solid fa-chevron-' + (open ? 'up' : 'down') + '"></i>');
    });
});

<div class="section-full p-t150 p-b120 site-bg-white twm-blog-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container-fluid">

        <x-site.section-head align="center"
            :small="site('home.blog.small_title')"
            :title="site('home.blog.title')" />

        <div class="section-content">
            <div class="owl-carousel twm-blog-carousel m-b30">
                @foreach ($posts as $post)
                    <div class="item">
                        <x-site.post-card :post="$post" />
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>

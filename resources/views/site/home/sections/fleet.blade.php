<div class="section-full p-t150 p-b120 site-bg-white twm-blog-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container-fluid">

        <x-site.section-head align="center"
            :small="site('home.fleet.small_title')"
            :title="site('home.fleet.title')" />

        <div class="section-content">
            <div class="owl-carousel twm-vehicle-fleet-carousel m-b30">
                @foreach ($fleet as $machine)
                    <div class="item">
                        <x-site.machine-card :machine="$machine" />
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>

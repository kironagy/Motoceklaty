<div class="about-section employee-section">
    <div class="container">

        <x-site.section-head align="center" small-class="site-accent-cyan"
            :small="site('about.team.small_title')"
            :title="site('about.team.title')" />

        <div class="row" dir="rtl">
            @foreach ($team as $member)
                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 m-b30">
                    <x-site.team-card :member="$member" variant="grid" />
                </div>
            @endforeach
        </div>

    </div>
</div>

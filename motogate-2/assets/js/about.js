"use strict";
document.querySelectorAll('#mobileMenu a').forEach(link=>link.addEventListener('click',()=>bootstrap.Offcanvas.getInstance(document.getElementById('mobileMenu'))?.hide()));
document.getElementById('aboutFilm').addEventListener('hidden.bs.modal',()=>document.querySelector('#aboutFilm video').pause());

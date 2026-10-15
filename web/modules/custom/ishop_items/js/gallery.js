/**
 * @file
 * Item photo gallery: tapping a thumbnail shows it as the large photo.
 *
 * Without JavaScript, the thumbnail links open the large photo instead.
 */
((Drupal, once) => {
  Drupal.behaviors.ishopGallery = {
    attach(context) {
      once('ishop-gallery', '.ishop-gallery', context).forEach((gallery) => {
        const cover = gallery.querySelector('.ishop-gallery__cover img');
        gallery.querySelectorAll('.ishop-gallery__thumb').forEach((thumb, i, all) => {
          thumb.addEventListener('click', (e) => {
            e.preventDefault();
            cover.src = thumb.dataset.large;
            cover.alt = cover.alt.replace(/\d+ of/, `${i + 1} of`);
            all.forEach((t) => t.classList.toggle('is-current', t === thumb));
          });
        });
      });
    },
  };
})(Drupal, once);

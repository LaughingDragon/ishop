/**
 * @file
 * Check sold prices: the buttons search for whatever is in Search words.
 *
 * Each link carries data-sold-param (its site's search parameter); typing
 * in the box rewrites that parameter, so Cody can drop words when a site
 * finds nothing.
 */
((Drupal, once) => {
  Drupal.behaviors.ishopSoldSearch = {
    attach(context) {
      once('ishop-sold-words', '[data-ishop-sold-words]', context).forEach((input) => {
        const panel = input.closest('.ishop-sold-prices');
        const update = () => {
          const words = input.value.trim().replace(/\s+/g, ' ');
          if (!words) {
            return;
          }
          panel.querySelectorAll('a[data-sold-param]').forEach((link) => {
            const url = new URL(link.href);
            url.searchParams.set(link.dataset.soldParam, words);
            link.href = url.toString();
          });
        };
        input.addEventListener('input', update);
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
          }
        });
      });
    },
  };
})(Drupal, once);

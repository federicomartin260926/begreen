import $ from 'jquery';

$(document).ready(function () {
  const speed = 200;

  $(".counter").each(function () {
    const $counter = $(this);

    function updateCount() {
      const target = parseInt($counter.data("target"), 10);
      const count = parseInt($counter.text(), 10);
      const increment = target / speed;

      if (count < target) {
        $counter.text(Math.ceil(count + increment));
        setTimeout(updateCount, 10);
      } else {
        $counter.text(target);
      }
    }

    updateCount();
  });
});


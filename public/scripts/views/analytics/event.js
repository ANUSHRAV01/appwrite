(function (window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics",
    controller: function (element) {
      let action = element.getAttribute("data-analytics-event") || "click";
      let doNotTrack = window.navigator.doNotTrack;

      if (doNotTrack == '1') {
        return;
      }

      element.addEventListener(action, function () {
        let category =
          element.getAttribute("data-analytics-category") || "undefined";
        let label = element.getAttribute("data-analytics-label") || "undefined";

        fetch('http://localhost:2080/v1/analytics', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            source: 'console',
            category: category,
            action: action,
            label: label,
            version: null,
            additionalData: null,
            url: window.location.href
          })
        });
      });
    }
  });
})(window);

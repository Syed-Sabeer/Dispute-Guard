/* Dispute Guard marketing site - small progressive enhancements. */
(function () {
  "use strict";

  /* Mobile navigation toggle -------------------------------------------- */
  var toggle = document.querySelector(".nav-toggle");
  var links = document.querySelector(".nav-links");

  if (toggle && links) {
    toggle.addEventListener("click", function () {
      var open = links.classList.toggle("open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    links.addEventListener("click", function (event) {
      if (event.target.tagName === "A") {
        links.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* Current year in the footer ------------------------------------------ */
  var years = document.querySelectorAll("[data-year]");
  for (var i = 0; i < years.length; i++) {
    years[i].textContent = String(new Date().getFullYear());
  }

  /* Contact form: compose an email in the visitor's mail client ---------- */
  var form = document.getElementById("contact-form");
  if (form) {
    var status = document.getElementById("form-status");

    form.addEventListener("submit", function (event) {
      event.preventDefault();

      var value = function (id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : "";
      };

      var name = value("name");
      var email = value("email");
      var store = value("store");
      var topic = value("topic");
      var message = value("message");

      if (!name || !email || !message) {
        if (status) {
          status.textContent = "Please add your name, email address and a message first.";
          status.style.color = "#b26a00";
        }
        return;
      }

      var subject = "[" + (topic || "General") + "] Dispute Guard enquiry from " + name;

      var body =
        "Name: " + name + "\n" +
        "Email: " + email + "\n" +
        "Store domain: " + (store || "not provided") + "\n" +
        "Topic: " + (topic || "General") + "\n\n" +
        "Message:\n" + message + "\n";

      var href =
        "mailto:disputeguardapp@gmail.com" +
        "?subject=" + encodeURIComponent(subject) +
        "&body=" + encodeURIComponent(body);

      window.location.href = href;

      if (status) {
        status.textContent =
          "Your email app should now open with the message ready to send. " +
          "If nothing happens, write to disputeguardapp@gmail.com directly.";
        status.style.color = "#12855f";
      }
    });
  }
})();

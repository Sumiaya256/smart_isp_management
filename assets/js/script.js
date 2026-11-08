document.addEventListener("DOMContentLoaded", function () {
  // ============================================
  // 1. AUTH CHECK ON PAGE LOAD
  // ============================================
  async function checkAuth() {
    try {
      const response = await fetch("backend/public/auth/me.php", {
        credentials: "same-origin",
      });
      if (!response.ok) {
        window.location.href = "login.html";
      }
    } catch (error) {
      console.warn("Auth check failed:", error);
    }
  }
  checkAuth();

  // ============================================
  // 2. SIDEBAR TOGGLE
  // ============================================
  const sidebarToggle = document.querySelector(".sidebar-toggle");
  const sidebar = document.querySelector(".sidebar");

  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", function () {
      sidebar.classList.toggle("active");

      if (window.innerWidth > 768) {
        if (sidebar.style.marginLeft === "-250px") {
          sidebar.style.marginLeft = "0";
        } else {
          sidebar.style.marginLeft = "-250px";
        }
      }
    });
  }

  // ============================================
  // 3. SIDEBAR DROPDOWN FUNCTIONALITY
  // ============================================
  const dropdownItems = document.querySelectorAll(".has-submenu > a");

  dropdownItems.forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault();
      const parent = this.parentElement;
      parent.classList.toggle("open");

      // Close other opened menus
      document.querySelectorAll(".has-submenu.open").forEach((openItem) => {
        if (openItem !== parent) {
          openItem.classList.remove("open");
        }
      });
    });
  });

  // ============================================
  // 4. AUTO-EXPAND ACTIVE SUBMENU
  // ============================================
  const currentPage = window.location.pathname.split("/").pop() || "index.html";
  const activeLink = document.querySelector(`.sidebar a[href="${currentPage}"]`);
  if (activeLink) {
    activeLink.classList.add("active");
    const parentSubmenu = activeLink.closest(".has-submenu");
    if (parentSubmenu) {
      parentSubmenu.classList.add("open");
    }
  }

  // ============================================
  // 5. HOME BUTTON - REDIRECT TO DASHBOARD
  // ============================================
  const headerButtons = document.querySelectorAll(".header-btn");
  headerButtons.forEach((button) => {
    const text = (button.textContent || "").toLowerCase();

    // Home button - always goes to index.html
    if (text.includes("home")) {
      button.href = "index.html";
      button.addEventListener("click", function (event) {
        event.preventDefault();
        window.location.href = "index.html";
      });
    }

    // Logout button
    if (text.includes("log out")) {
      button.addEventListener("click", async function (event) {
        event.preventDefault();

        try {
          await fetch("backend/public/auth/logout.php", {
            method: "POST",
            credentials: "same-origin",
          });
        } catch (error) {
          // Ignore network errors and still redirect to login page.
        }

        window.location.href = "login.html";
      });
    }
  });

  // ============================================
  // 6. UNIVERSAL SEARCH BOX FUNCTIONALITY
  // ============================================
  const searchInputs = document.querySelectorAll('input[placeholder="Search Customer"]');
  searchInputs.forEach((searchInput) => {
    let searchTimeout;

    searchInput.addEventListener("input", function (e) {
      clearTimeout(searchTimeout);
      const query = e.target.value.trim();

      if (query.length < 2) {
        // Close search modal if open
        const searchModal = document.getElementById("searchModal");
        if (searchModal) {
          searchModal.style.display = "none";
        }
        return;
      }

      searchTimeout = setTimeout(() => {
        performSearch(query);
      }, 300);
    });
  });

  async function performSearch(query) {
    try {
      const response = await fetch(`backend/public/dashboard/search.php?q=${encodeURIComponent(query)}`, {
        credentials: "same-origin",
      });
      const data = await response.json();

      // Check if search modal exists on this page
      const searchModal = document.getElementById("searchModal");
      if (!searchModal) {
        return; // Not on dashboard page, search not supported
      }

      if (data.ok && data.results.length > 0) {
        const resultsDiv = document.getElementById("searchResults");
        resultsDiv.innerHTML = data.results
          .map(
            (client) => `
          <div style="background: white; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px;">
            <h3 style="margin: 0 0 10px 0; color: #333;">${escapeHtml(client.full_name)}</h3>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Code:</strong> ${escapeHtml(client.client_code)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Phone:</strong> ${escapeHtml(client.phone)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Email:</strong> ${escapeHtml(client.email)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Package:</strong> ${escapeHtml(client.package_name || "N/A")}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Status:</strong> <span style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 3px;">${escapeHtml(client.status)}</span></p>
          </div>
        `
          )
          .join("");

        searchModal.style.display = "block";
      } else {
        const resultsDiv = document.getElementById("searchResults");
        resultsDiv.innerHTML =
          '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #999;">No results found</div>';
        searchModal.style.display = "block";
      }
    } catch (error) {
      console.error("Search failed:", error);
    }
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // Make search modal closer available globally
  window.closeSearchModal = function () {
    const searchModal = document.getElementById("searchModal");
    if (searchModal) {
      searchModal.style.display = "none";
    }
    const searchInputs = document.querySelectorAll('input[placeholder="Search Customer"]');
    searchInputs.forEach((input) => {
      input.value = "";
    });
  };
});


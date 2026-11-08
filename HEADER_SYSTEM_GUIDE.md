# Header Component System - Implementation Guide

## Overview

The **Header Component System** is a centralized, reusable header that works across all HTML pages in your application. Instead of duplicating header code in every file, the header is now:

- ✅ Loaded dynamically from `assets/html/header.html`
- ✅ Initialized by `assets/js/header-loader.js`
- ✅ Available on all pages with minimal code
- ✅ Easy to maintain (one place, one update)

---

## How It Works

### Architecture

```
┌─────────────────────────────────────┐
│       Your HTML Page                │
│  ┌──────────────────────────────┐   │
│  │ <div id="app-header"></div>  │   │← Empty container
│  └──────────────────────────────┘   │
│                                     │
│  <script src="header-loader.js">    │← Loads header
│  </script>                          │
└─────────────────────────────────────┘
                  ↓
        ┌─────────────────────┐
        │ assets/html/        │
        │ header.html         │
        │ (Sidebar + Header)  │
        └─────────────────────┘
                  ↓
        ┌─────────────────────┐
        │ assets/js/          │
        │ header-loader.js    │
        │ (Initialization)    │
        └─────────────────────┘
```

### Available Features (Automatically Initialized)

| Feature | Status | Details |
|---------|--------|---------|
| **Auth Check** | ✅ | Redirects to login if not authenticated |
| **Sidebar Toggle** | ✅ | Hamburger menu expands/collapses |
| **Dropdown Menus** | ✅ | Sub-menus expand/collapse |
| **Active Page Highlight** | ✅ | Current page highlighted in menu |
| **HOME Button** | ✅ | Navigates to index.html (dashboard) |
| **Logout Button** | ✅ | Logs out and redirects to login |
| **Search Box** | ✅ | Real-time client search (2+ characters) |
| **Search Modal** | ✅ | Results displayed in modal popup |

---

## For New Pages

### Basic Template

Create a new HTML file using this structure:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Promee International - [PAGE_TITLE]</title>
  
  <!-- Stylesheets -->
  <link rel="stylesheet" href="assets/css/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
</head>
<body>
  <!-- Header Container (loads automatically) -->
  <div id="app-header"></div>

  <!-- Your Page Content -->
  <div id="app-page-content" class="page-content">
    <h1>Your Page Title</h1>
    <!-- Add your content here -->
  </div>

  <!-- Load Header System -->
  <script src="assets/js/header-loader.js"></script>

  <!-- Optional: Your Page-Specific Scripts -->
  <script>
    // Add your page functionality here
  </script>
</body>
</html>
```

### What You Need

**Minimum 3 things:**

1. `<div id="app-header"></div>` - Container where header loads
2. `<div id="app-page-content" class="page-content">` - Your page content
3. `<script src="assets/js/header-loader.js"></script>` - Loads the header

That's it! Everything else is automatic.

---

## For Existing Pages

### Option A: Keep Current Setup (Still Works ✅)

Your existing pages with hardcoded headers continue to work. No changes needed. Only use the new system for new pages.

### Option B: Migrate to New System (Recommended)

To migrate an existing page (e.g., `client_list.html`):

#### Before (Old Way - 100+ lines of header code)

```html
<body>
  <aside class="sidebar">
    <!-- 100+ lines of sidebar HTML -->
  </aside>
  <div class="main-content">
    <header>
      <!-- 50+ lines of header HTML -->
    </header>
    <div class="page-content">
      <!-- Your page content -->
    </div>
  </div>
</body>
```

#### After (New Way - Dynamic Header)

```html
<body>
  <!-- Header loads here -->
  <div id="app-header"></div>

  <!-- Your page content -->
  <div id="app-page-content" class="page-content">
    <!-- Your page content -->
  </div>

  <!-- Load header system -->
  <script src="assets/js/header-loader.js"></script>
  
  <!-- Keep your page-specific scripts -->
  <script>
    // Your functionality here
  </script>
</body>
```

---

## Files Created

| File | Purpose | Size |
|------|---------|------|
| `assets/html/header.html` | Reusable header component | 11KB |
| `assets/js/header-loader.js` | Header loader & initialization | 8KB |
| `TEMPLATE.html` | Template for new pages | 1KB |
| `test-header.html` | Test page to verify system | 2KB |

---

## Testing

### Verify System Works

1. Open `test-header.html` in browser
2. You should see:
   - ✅ Sidebar with all menus
   - ✅ Header with search box
   - ✅ All buttons active (Home, Logout)
3. Try these:
   - Type in search box (e.g., "act" or "client")
   - Click hamburger menu
   - Click menu items
   - Click Home button

---

## How to Use Existing Headers

### Current State

All existing pages still have hardcoded headers. The new system is **additive** - it doesn't break existing pages.

### Paths Forward

**For maintenance:** 
- New features go in `assets/html/header.html`
- Old pages get updates automatically when migrated
- Simple pages can use new system immediately

**For updates:**
- Any change to header affects ALL pages using the system
- Reduces maintenance burden by 100+

---

## Benefits

| Benefit | Impact |
|---------|--------|
| **Single Source** | Update header once, affects all pages |
| **Faster Development** | New pages need minimal HTML boilerplate |
| **Consistency** | All pages have identical header behavior |
| **Maintainability** | No header code duplication |
| **Scalability** | Easy to add new pages |
| **Backward Compatible** | Old pages still work |

---

## Troubleshooting

**Q: Header not loading?**
- Ensure `assets/js/header-loader.js` is included before closing `</body>`
- Check browser console for errors
- Ensure `assets/html/header.html` path is correct

**Q: Search not working?**
- Verify `backend/public/dashboard/search.php` endpoint exists
- Check browser console for "Search failed" errors
- Ensure user is authenticated

**Q: Sidebar not toggling?**
- Check that FontAwesome CSS is loaded
- Verify `sidebar-toggle` class exists in header.html
- Check browser console for JavaScript errors

---

## Next Steps

1. **Test it:** Open `test-header.html` and verify functionality
2. **New pages:** Use the template for any new pages
3. **Optional migration:** Migrate existing pages one by one when convenient
4. **Enjoy:** Maintain your system in one place!

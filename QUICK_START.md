# QUICK START - Header Component System

## Copy-Paste Template for New Pages

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Promee International - PAGE_NAME</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
</head>
<body>
  <div id="app-header"></div>
  <div id="app-page-content" class="page-content">
    <h1>Your Page Title</h1>
    <!-- Your content here -->
  </div>
  <script src="assets/js/header-loader.js"></script>
</body>
</html>
```

## What You Get Automatically

✅ Sidebar with navigation menu  
✅ Header with search box  
✅ Logout button  
✅ Home button  
✅ Auth check (redirects if not logged in)  
✅ Search modal with results  

## Three Files You Need to Know

| File | What It Does |
|------|-------------|
| `assets/html/header.html` | All the header/sidebar HTML |
| `assets/js/header-loader.js` | Loads & initializes the header |
| `TEMPLATE.html` | Example page using the system |

## One Change to Existing Pages (Optional)

Replace this mess:
```html
<!-- 150 lines of header/sidebar HTML -->
<aside class="sidebar">...</aside>
<div class="main-content">
  <header>...</header>
</div>
```

With this:
```html
<div id="app-header"></div>
<div id="app-page-content" class="page-content">
  <!-- Your content -->
</div>
<script src="assets/js/header-loader.js"></script>
```

## Test It

Open `test-header.html` in browser → Everything should work (search, menu, buttons)

## That's It!

Next page? Copy the template above, replace title/content, done. Header loads automatically!

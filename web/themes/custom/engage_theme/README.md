# Engage Theme

A custom Drupal theme named **engage_theme** built with **Tailwind CSS** and a simple frontend workflow using Node.js and npm.

This README provides instructions for:

* Installing Node.js dependencies
* Compiling CSS using Tailwind
* Running available npm scripts
* Adding custom Twig templates

---

## 📁 Theme Structure (Overview)

A common folder structure for this theme looks like:

```
engage_theme/
├── assets/
│   ├── src/
│   │   └── css/
│   │       └── styles.css
│   └── dist/
│       └── css/
├── templates/
│   └── (custom Twig templates)
├── engage_theme.info.yml
├── engage_theme.breakpoints.yml
├── engage_theme.libraries.yml
├── package.json
└── tailwind.config.js
```

---

## 🔧 Installing Node Modules

Before compiling Tailwind CSS, install all required dependencies.

### **1. Install Node.js**

Download from: [https://nodejs.org/](https://nodejs.org/)

### **2. Install dependencies using npm**

Run inside the theme directory (`/web/themes/custom/engage_theme`):

```
npm install
```

This installs the `devDependencies` listed in `package.json` (currently Tailwind CSS).

---

## 🧵 Tailwind CSS Compilation

Your `package.json` includes the following scripts:

```json
"scripts": {
  "build": "npx tailwindcss build -i assets/src/css/styles.css -o assets/dist/css/styles.css",
  "build:prod": "npx tailwindcss build -i assets/src/css/styles.css -o assets/dist/css/styles.css --minify",
  "watch": "npx tailwindcss -i assets/src/css/styles.css -o assets/dist/css/styles.css --watch"
}
```

### ### **1. build**

```
npm run build
```

* Processes `assets/src/css/styles.css`
* Uses Tailwind to generate the final CSS
* Outputs the compiled CSS into: `assets/dist/css/styles.css`
* **Non-minified**, suitable for local development

### **2. build:prod**

```
npm run build:prod
```

* Same as `build`, but includes the `--minify` flag
* Output CSS is compressed and optimized
* Use for **production deployments**

### **3. watch**

```
npm run watch
```

* Watches for changes in your templates, CSS, and Tailwind config
* Automatically rebuilds `styles.css` on save
* Ideal for active development

---

## 🧩 Adding Custom Twig Templates

Drupal allows overriding templates by placing Twig files inside the `templates/` directory.

### **Steps to add a template override:**

1. Create or copy a Twig template file inside:

   ```
   engage_theme/templates/
   ```

2. Clear Drupal caches so the system recognizes your new template:

   ```
   drush cr
   ```

3. Customize your template as needed.

### **Common examples:**

* Override a page template:

  ```
  templates/page.html.twig
  ```
* Override a node template for a content type `article`:

  ```
  templates/node--article.html.twig
  ```
* Override a block template:

  ```
  templates/block--my-custom-block.html.twig
  ```

You can find suggested template names by enabling Twig debug mode.

---

## 📘 Tailwind Configuration

Your theme should include a `tailwind.config.js`, for example:

```
module.exports = {
  content: [
    "./templates/**/*.html.twig",
    "./assets/src/**/*.css"
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
```

Ensures Tailwind scans Twig templates and CSS files for class names.

---

## 🚀 Development Workflow

1. Install dependencies:

   ```
   npm install
   ```
2. Run file watcher during development:

   ```
   npm run watch
   ```
3. Build production-ready CSS before deployment:

   ```
   npm run build:prod
   ```
4. Add Twig overrides in `templates/` and run:

   ```
   drush cr
   ```

---

## 📝 Notes

* Make sure your theme library in `engage_theme.libraries.yml` points to `assets/dist/css/styles.css`.
* Always rebuild your CSS when updating Tailwind classes.
* Minify for production to reduce page load times.

---
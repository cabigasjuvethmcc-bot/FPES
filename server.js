const express = require('express');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

// Root of the PHP app
const publicDir = __dirname;

// Serve all assets (CSS, JS, images, etc.)
app.use(express.static(publicDir));

// Default route – serves the main entry file
app.get('/', (req, res) => {
  res.sendFile(path.join(publicDir, 'index.php'));
});

// Fallback for any unmatched route – you can customize as needed
app.get('*', (req, res) => {
  res.sendFile(path.join(publicDir, 'index.php'));
});

app.listen(PORT, () => {
  console.log(`Server listening on port ${PORT}`);
});

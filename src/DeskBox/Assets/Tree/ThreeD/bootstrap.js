import('./tree3d.js').catch(error => {
  window.chrome?.webview?.postMessage({ type: 'error', message: String(error?.message || error) });
});

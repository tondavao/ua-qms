/**
 * UA-QMS Document Scanner Image Processing Library
 * Client-Side computer vision for document boundary detection, perspective warping, and smart color enhancement.
 */
(function(window) {
    const ScannerProcessor = {};

    // Gaussian Elimination solver for linear equations (homography)
    function gaussElimination(A, B) {
        const n = B.length;
        for (let i = 0; i < n; i++) {
            let maxRow = i;
            for (let k = i + 1; k < n; k++) {
                if (Math.abs(A[k][i]) > Math.abs(A[maxRow][i])) {
                    maxRow = k;
                }
            }
            const temp = A[i]; A[i] = A[maxRow]; A[maxRow] = temp;
            const t = B[i]; B[i] = B[maxRow]; B[maxRow] = t;
            
            if (Math.abs(A[i][i]) < 1e-12) {
                // System is singular
                return null;
            }
            
            for (let k = i + 1; k < n; k++) {
                const factor = A[k][i] / A[i][i];
                B[k] -= factor * B[i];
                for (let j = i; j < n; j++) {
                    A[k][j] -= factor * A[i][j];
                }
            }
        }
        
        const x = new Array(n);
        for (let i = n - 1; i >= 0; i--) {
            let sum = 0;
            for (let j = i + 1; j < n; j++) {
                sum += A[i][j] * x[j];
            }
            x[i] = (B[i] - sum) / A[i][i];
        }
        return x;
    }

    // Calculates homography matrix mapping dst coordinates back to src coordinates (inverse transform)
    function getPerspectiveTransform(src, dst) {
        const A = [];
        const B = [];
        for (let i = 0; i < 4; i++) {
            const x = dst[i].x;
            const y = dst[i].y;
            const u = src[i].x;
            const v = src[i].y;
            A.push([x, y, 1, 0, 0, 0, -x*u, -y*u]);
            B.push(u);
            A.push([0, 0, 0, x, y, 1, -x*v, -y*v]);
            B.push(v);
        }
        const coeffs = gaussElimination(A, B);
        if (!coeffs) return null;
        coeffs.push(1); // h22 = 1
        return coeffs;
    }

    /**
     * Automatically detects document boundary corners in an image.
     * Assumes a light document placed on a darker background.
     * Returns an array of 4 corners: [Top-Left, Top-Right, Bottom-Left, Bottom-Right]
     */
    ScannerProcessor.detectDocumentCorners = function(canvas) {
        const ctx = canvas.getContext('2d');
        const W = canvas.width;
        const H = canvas.height;
        const imgData = ctx.getImageData(0, 0, W, H);
        const data = imgData.data;

        // Downsample to a fast analytical grid to find threshold
        let totalVal = 0;
        let count = 0;
        for (let i = 0; i < data.length; i += 40) {
            const r = data[i];
            const g = data[i+1];
            const b = data[i+2];
            const gray = 0.2126 * r + 0.7152 * g + 0.0722 * b;
            totalVal += gray;
            count++;
        }
        // Adaptive threshold boundary
        const threshold = (totalVal / count) * 1.15;

        // Search for corners based on extreme points of thresholded mask
        let minSum = Infinity, maxSum = -Infinity;
        let minDiff = Infinity, maxDiff = -Infinity;
        
        let tl = { x: 0, y: 0 };
        let br = { x: W, y: H };
        let tr = { x: W, y: 0 };
        let bl = { x: 0, y: H };
        
        let detected = false;
        
        // Scan a 200x200 grid for performance
        const stepX = Math.max(1, Math.floor(W / 200));
        const stepY = Math.max(1, Math.floor(H / 200));

        for (let y = 0; y < H; y += stepY) {
            for (let x = 0; x < W; x += stepX) {
                const idx = (y * W + x) * 4;
                const r = data[idx];
                const g = data[idx+1];
                const b = data[idx+2];
                const gray = 0.2126 * r + 0.7152 * g + 0.0722 * b;
                
                // If pixel is paper-colored
                if (gray > threshold) {
                    detected = true;
                    const sum = x + y;
                    const diff = x - y;
                    
                    if (sum < minSum) {
                        minSum = sum;
                        tl = { x, y };
                    }
                    if (sum > maxSum) {
                        maxSum = sum;
                        br = { x, y };
                    }
                    if (diff > maxDiff) {
                        maxDiff = diff;
                        tr = { x, y };
                    }
                    if (diff < minDiff) {
                        minDiff = diff;
                        bl = { x, y };
                    }
                }
            }
        }

        // If no clean document contrast is found, fall back to safe default margins
        if (!detected || (tl.x === br.x && tl.y === br.y)) {
            const marginX = W * 0.05;
            const marginY = H * 0.05;
            return [
                { x: marginX, y: marginY }, // TL
                { x: W - marginX, y: marginY }, // TR
                { x: marginX, y: H - marginY }, // BL
                { x: W - marginX, y: H - marginY } // BR
            ];
        }

        return [tl, tr, bl, br];
    };

    /**
     * Warps the perspective of a source canvas to match the detected document corners.
     * Writes result onto a target canvas (which can be landscape or portrait).
     */
    ScannerProcessor.warpDocument = function(srcCanvas, dstCanvas, corners) {
        const dstW = dstCanvas.width;
        const dstH = dstCanvas.height;
        
        const targetCorners = [
            { x: 0, y: 0 },       // TL
            { x: dstW, y: 0 },    // TR
            { x: 0, y: dstH },    // BL
            { x: dstW, y: dstH }  // BR
        ];

        const coeffs = getPerspectiveTransform(corners, targetCorners);
        if (!coeffs) {
            // Fallback: draw directly if math fails
            const dstCtx = dstCanvas.getContext('2d');
            dstCtx.drawImage(srcCanvas, 0, 0, dstW, dstH);
            return;
        }

        const srcCtx = srcCanvas.getContext('2d');
        const dstCtx = dstCanvas.getContext('2d');
        const srcW = srcCanvas.width;
        const srcH = srcCanvas.height;
        
        const srcData = srcCtx.getImageData(0, 0, srcW, srcH);
        const dstData = dstCtx.createImageData(dstW, dstH);
        
        const s = srcData.data;
        const d = dstData.data;

        for (let y = 0; y < dstH; y++) {
            for (let x = 0; x < dstW; x++) {
                // Compute inverse perspective transform coordinates in src
                const w = coeffs[6]*x + coeffs[7]*y + coeffs[8];
                const u = Math.round((coeffs[0]*x + coeffs[1]*y + coeffs[2]) / w);
                const v = Math.round((coeffs[3]*x + coeffs[4]*y + coeffs[5]) / w);

                if (u >= 0 && u < srcW && v >= 0 && v < srcH) {
                    const dstIdx = (y * dstW + x) * 4;
                    const srcIdx = (v * srcW + u) * 4;
                    
                    d[dstIdx]   = s[srcIdx];
                    d[dstIdx+1] = s[srcIdx+1];
                    d[dstIdx+2] = s[srcIdx+2];
                    d[dstIdx+3] = 255;
                }
            }
        }
        dstCtx.putImageData(dstData, 0, 0);
    };

    /**
     * Smart Clarity Enhancement: Clears background shadows and optimizes contrast
     * without stripping away document colors (signatures, stamps, seals remain colored).
     */
    ScannerProcessor.applySmartEnhancement = function(canvas) {
        const ctx = canvas.getContext('2d');
        const W = canvas.width;
        const H = canvas.height;
        const imgData = ctx.getImageData(0, 0, W, H);
        const data = imgData.data;

        // Step 1: Create a downsampled grayscale representation for lighting analysis
        const lowW = 80;
        const lowH = 60;
        const lowCanvas = document.createElement('canvas');
        lowCanvas.width = lowW;
        lowCanvas.height = lowH;
        const lowCtx = lowCanvas.getContext('2d');
        lowCtx.drawImage(canvas, 0, 0, lowW, lowH);
        
        const lowData = lowCtx.getImageData(0, 0, lowW, lowH);
        const lowPixels = lowData.data;
        const lightMap = new Float32Array(lowW * lowH);

        for (let i = 0; i < lowPixels.length; i += 4) {
            const r = lowPixels[i];
            const g = lowPixels[i+1];
            const b = lowPixels[i+2];
            lightMap[i/4] = 0.2126 * r + 0.7152 * g + 0.0722 * b;
        }

        // Apply blur to lightMap to smooth out light gradients
        const blurredMap = new Float32Array(lowW * lowH);
        const radius = 3;
        for (let y = 0; y < lowH; y++) {
            for (let x = 0; x < lowW; x++) {
                let sum = 0;
                let count = 0;
                for (let ky = -radius; ky <= radius; ky++) {
                    for (let kx = -radius; kx <= radius; kx++) {
                        const ny = y + ky;
                        const nx = x + kx;
                        if (nx >= 0 && nx < lowW && ny >= 0 && ny < lowH) {
                            sum += lightMap[ny * lowW + nx];
                            count++;
                        }
                    }
                }
                blurredMap[y * lowW + x] = sum / count;
            }
        }

        // Step 2: Flatten background lighting on original high-resolution pixels
        for (let y = 0; y < H; y++) {
            // Find y coordinate in lowres map
            const lowY = Math.min(lowH - 1, Math.floor((y / H) * lowH));
            for (let x = 0; x < W; x++) {
                const lowX = Math.min(lowW - 1, Math.floor((x / W) * lowW));
                const localLight = blurredMap[lowY * lowW + lowX];
                
                const idx = (y * W + x) * 4;
                const r = data[idx];
                const g = data[idx+1];
                const b = data[idx+2];

                // Scale lighting dynamically to target white background (around 235)
                const factor = 238 / Math.max(40, localLight);
                
                // Boost text contrast locally
                let nr = Math.min(255, r * factor);
                let ng = Math.min(255, g * factor);
                let nb = Math.min(255, b * factor);

                // Local adaptive sharpening threshold to make text darker/crisper
                const gray = 0.2126 * nr + 0.7152 * ng + 0.0722 * nb;
                if (gray < 140) {
                    // Darken text to increase clarity
                    const darken = 0.85;
                    nr *= darken;
                    ng *= darken;
                    nb *= darken;
                } else if (gray > 210) {
                    // Flatten clean white backgrounds
                    nr = 255;
                    ng = 255;
                    nb = 255;
                }

                data[idx]   = nr;
                data[idx+1] = ng;
                data[idx+2] = nb;
            }
        }

        ctx.putImageData(imgData, 0, 0);
    };

    window.ScannerProcessor = ScannerProcessor;
})(window);

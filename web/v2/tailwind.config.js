/** Tailwind CSS 配置 · OKX 量化面板 v2
 *  内容扫描: index.html + assets/*.js
 *  编译: tailwindcss -c tailwind.config.js -i src/tw.css -o assets/tw.css --minify
 */
module.exports = {
  content: [
    "E:/finally-main/web/v2/index.html",
    "E:/finally-main/web/v2/assets/app.js",
    "E:/finally-main/web/v2/assets/okx.js",
    "E:/finally-main/web/v2/assets/chart.js",
  ],
  theme: {
    extend: {
      colors: {
        // 深色交易终端底色
        ink: {
          900: "#080b12", // 页面底
          850: "#0b1018",
          800: "#0e1420", // 卡片
          750: "#111927",
          700: "#141d2c", // 卡片头
          600: "#1b2434", // 边框
          500: "#253247", // 边框 hover
          400: "#33425c",
        },
        // 中国市场惯例: 涨=红, 跌=绿
        up: "#ff4d4f",
        upsoft: "#ff7a7c",
        dn: "#0ecb81",
        dnsoft: "#3ddc9a",
        gold: "#f0b90b",
        goldsoft: "#ffd44d",
        info: "#3b82f6",
        violet: "#8b5cf6",
        txt: {
          1: "#e8eefb",
          2: "#b3c0d6",
          3: "#7d8ca6",
          4: "#55637a",
        },
      },
      fontFamily: {
        sans: ["Inter", "system-ui", "-apple-system", "Segoe UI", "PingFang SC", "Microsoft YaHei", "sans-serif"],
        mono: ["JetBrains Mono", "SFMono-Regular", "Consolas", "Menlo", "monospace"],
      },
      fontSize: {
        "2xs": ["10px", "14px"],
        "3xs": ["9px", "12px"],
      },
      boxShadow: {
        card: "0 1px 0 0 rgba(255,255,255,.02) inset, 0 8px 24px -12px rgba(0,0,0,.7)",
        glow: "0 0 0 1px rgba(240,185,11,.35), 0 0 22px -6px rgba(240,185,11,.35)",
        upglow: "0 0 18px -6px rgba(255,77,79,.55)",
        dnglow: "0 0 18px -6px rgba(14,203,129,.55)",
      },
      keyframes: {
        pulse2: {
          "0%,100%": { opacity: "1" },
          "50%": { opacity: ".35" },
        },
        slidein: {
          from: { opacity: "0", transform: "translateY(-4px)" },
          to: { opacity: "1", transform: "translateY(0)" },
        },
        tick: {
          from: { background: "rgba(240,185,11,.22)" },
          to: { background: "transparent" },
        },
        flashup: {
          "0%": { background: "rgba(255,77,79,.34)", boxShadow: "0 0 16px -4px rgba(255,77,79,.7)" },
          "100%": { background: "transparent", boxShadow: "0 0 0 0 rgba(255,77,79,0)" },
        },
        flashdn: {
          "0%": { background: "rgba(14,203,129,.34)", boxShadow: "0 0 16px -4px rgba(14,203,129,.7)" },
          "100%": { background: "transparent", boxShadow: "0 0 0 0 rgba(14,203,129,0)" },
        },
        arrowpop: {
          "0%": { transform: "translateY(3px)", opacity: ".35" },
          "55%": { transform: "translateY(-2px)", opacity: "1" },
          "100%": { transform: "translateY(0)", opacity: "1" },
        },
      },
      animation: {
        pulse2: "pulse2 1.6s ease-in-out infinite",
        slidein: "slidein .22s ease-out",
        tick: "tick .9s ease-out",
        flashup: "flashup .8s ease-out",
        flashdn: "flashdn .8s ease-out",
        arrowpop: "arrowpop .45s ease-out",
      },
    },
  },
  plugins: [],
};

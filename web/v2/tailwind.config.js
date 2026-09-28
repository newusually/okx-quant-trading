/** ============================================================
 *  tailwind.config.js — Tailwind CSS 编译配置 · OKX 量化面板 v2
 *  文件职责: 定义 Tailwind 的内容扫描范围与设计系统(色板/字体/字号/
 *            阴影/动画), 供 CLI 编译出 assets/tw.css(深色交易终端主题)。
 *  功能清单:
 *    1) content: 扫描 index.html + assets 下 4 个 js(类名都写在这些文件里)
 *    2) colors: ink 深色梯度底色(900页面底→400高亮边框) + 语义色
 *       (up红涨/dn绿跌/gold金/info蓝/violet紫/txt四级文字灰)
 *    3) fontFamily: sans=Inter 系 / mono=JetBrains Mono 系
 *    4) fontSize: 2xs(10px)/3xs(9px) 两个超小号(终端密排界面)
 *    5) boxShadow: card卡片/glow金色光晕/upglow红/dnglow绿
 *    6) keyframes+animation: pulse2呼吸/slidein滑入/tick高亮/
 *       flashup红闪/flashdn绿闪/arrowpop箭头弹跳
 *  编译: tailwindcss -c tailwind.config.js -i src/tw.css -o assets/tw.css --minify
 * ============================================================ */
module.exports = {                              // CommonJS 导出配置对象
  content: [                                    // 类名扫描清单(只编译这里出现过的类)
    "E:/finally-main/web/v2/index.html",        // 模板骨架(绑定与静态类)
    "E:/finally-main/web/v2/assets/app.js",     // Vue 主应用(动态类名字符串)
    "E:/finally-main/web/v2/assets/okx.js",     // API/工具模块(可能拼类名)
    "E:/finally-main/web/v2/assets/chart.js",   // 图表控制器(可能拼类名)
  ],
  theme: {                                      // 主题定制区
    extend: {                                   // extend=在默认主题之上追加(不覆盖)
      colors: {                                 // 自定义色板
        // 深色交易终端底色
        ink: {                                  // ink=深蓝黑底色梯度(页面由深到浅)
          900: "#080b12", // 页面底
          850: "#0b1018", // 指标条格底
          800: "#0e1420", // 卡片
          750: "#111927", // 次级面板/表底
          700: "#141d2c", // 卡片头
          600: "#1b2434", // 边框
          500: "#253247", // 边框 hover
          400: "#33425c", // 最亮一档(描边/高亮)
        },
        // 中国市场惯例: 涨=红, 跌=绿
        up: "#ff4d4f",                          // 涨色(红)
        upsoft: "#ff7a7c",                      // 涨色浅变体
        dn: "#0ecb81",                          // 跌色(绿)
        dnsoft: "#3ddc9a",                      // 跌色浅变体
        gold: "#f0b90b",                        // 品牌金(高亮/金▲/选中态)
        goldsoft: "#ffd44d",                    // 金色浅变体(渐变用)
        info: "#3b82f6",                        // 信息蓝(顶点徽章等)
        violet: "#8b5cf6",                      // 紫色(底点徽章/KE说明)
        txt: {                                  // 文字四级灰(由亮到暗)
          1: "#e8eefb",                         // 主文字(最亮)
          2: "#b3c0d6",                         // 次级文字
          3: "#7d8ca6",                         // 辅助文字
          4: "#55637a",                         // 最弱(占位/说明)
        },
      },
      fontFamily: {                             // 字体族
        sans: ["Inter", "system-ui", "-apple-system", "Segoe UI", "PingFang SC", "Microsoft YaHei", "sans-serif"],   // 界面主字体(中文回退苹方/雅黑)
        mono: ["JetBrains Mono", "SFMono-Regular", "Consolas", "Menlo", "monospace"],   // 等宽字体(数字/代码)
      },
      fontSize: {                               // 追加超小字号(密排终端界面)
        "2xs": ["10px", "14px"],                // 2xs: 10px 字号/14px 行高
        "3xs": ["9px", "12px"],                 // 3xs: 9px 字号/12px 行高
      },
      boxShadow: {                              // 自定义阴影
        card: "0 1px 0 0 rgba(255,255,255,.02) inset, 0 8px 24px -12px rgba(0,0,0,.7)",   // 卡片: 内高光+外投影
        glow: "0 0 0 1px rgba(240,185,11,.35), 0 0 22px -6px rgba(240,185,11,.35)",   // 金色光晕(Logo)
        upglow: "0 0 18px -6px rgba(255,77,79,.55)",   // 涨红光晕
        dnglow: "0 0 18px -6px rgba(14,203,129,.55)",  // 跌绿光晕
      },
      keyframes: {                              // 关键帧定义
        pulse2: {                               // 呼吸灯(透明度脉动)
          "0%,100%": { opacity: "1" },          // 起/止: 全亮
          "50%": { opacity: ".35" },            // 中点: 变暗
        },
        slidein: {                              // 下拉面板滑入
          from: { opacity: "0", transform: "translateY(-4px)" },   // 从上方4px淡入
          to: { opacity: "1", transform: "translateY(0)" },        // 落位
        },
        tick: {                                 // 新条目高亮淡出
          from: { background: "rgba(240,185,11,.22)" },   // 起始金色底
          to: { background: "transparent" },    // 淡为透明
        },
        flashup: {                              // 上涨红闪(实时价)
          "0%": { background: "rgba(255,77,79,.34)", boxShadow: "0 0 16px -4px rgba(255,77,79,.7)" },   // 起始红底红晕
          "100%": { background: "transparent", boxShadow: "0 0 0 0 rgba(255,77,79,0)" },   // 淡出
        },
        flashdn: {                              // 下跌绿闪(实时价)
          "0%": { background: "rgba(14,203,129,.34)", boxShadow: "0 0 16px -4px rgba(14,203,129,.7)" },   // 起始绿底绿晕
          "100%": { background: "transparent", boxShadow: "0 0 0 0 rgba(14,203,129,0)" },   // 淡出
        },
        arrowpop: {                             // 涨跌箭头弹跳
          "0%": { transform: "translateY(3px)", opacity: ".35" },   // 从下方淡入
          "55%": { transform: "translateY(-2px)", opacity: "1" },   // 弹过头顶
          "100%": { transform: "translateY(0)", opacity: "1" },     // 回落定位
        },
      },
      animation: {                              // 动画工具类(配合 keyframes)
        pulse2: "pulse2 1.6s ease-in-out infinite",   // 呼吸: 1.6s 无限循环
        slidein: "slidein .22s ease-out",       // 滑入: 0.22s
        tick: "tick .9s ease-out",              // 高亮: 0.9s
        flashup: "flashup .8s ease-out",        // 红闪: 0.8s
        flashdn: "flashdn .8s ease-out",        // 绿闪: 0.8s
        arrowpop: "arrowpop .45s ease-out",     // 弹跳: 0.45s
      },
    },
  },
  plugins: [],                                  // 无第三方插件
};

import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'ZC Mail Service',
  description: '开源自托管的邮件 API 服务 — 零依赖 PHP 实现',
  lang: 'zh-CN',
  // 自定义域名 mail.zgric.top 直接挂在根路径；
  // 若改用 GitHub Pages 默认地址（/mail_zcx/），把这里改成 '/mail_zcx/'
  base: '/',

  // 仓库里的 docs/API.md 对外路由为 /api
  rewrites: {
    'API.md': 'api.md',
  },

  // 文档里大量出现 {{变量}} 示例，需关闭 Vue 在代码块中的插值
  markdown: {
    config(md) {
      const fence = md.renderer.rules.fence!
      md.renderer.rules.fence = (...args) =>
        fence(...args).replace('<pre>', '<pre v-pre>')

      const codeInline = md.renderer.rules.code_inline!
      md.renderer.rules.code_inline = (...args) =>
        codeInline(...args).replace('<code', '<code v-pre')
    },
  },

  themeConfig: {
    nav: [
      { text: '快速开始', link: '/guide' },
      { text: '宝塔部署', link: '/deploy-baota' },
      { text: 'SMTP 入站', link: '/smtp-in' },
      { text: 'API', link: '/api' },
      { text: '设计', link: '/architecture' },
      { text: 'GitHub', link: 'https://github.com/kuangxing6367/mail_zcx' },
    ],

    sidebar: [
      {
        text: '部署',
        items: [
          { text: '快速开始', link: '/guide' },
          { text: '宝塔面板部署', link: '/deploy-baota' },
          { text: 'SMTP 入站（25 端口）', link: '/smtp-in' },
        ],
      },
      {
        text: '参考',
        items: [
          { text: 'API 手册', link: '/api' },
          { text: '设计说明', link: '/architecture' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/kuangxing6367/mail_zcx' },
    ],

    outline: { level: [2, 3], label: '本页目录' },
    docFooter: { prev: '上一篇', next: '下一篇' },
    darkModeSwitchLabel: '主题',
    returnToTopLabel: '回到顶部',
    lastUpdated: { text: '最后更新' },
  },
})

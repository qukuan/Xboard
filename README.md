# Xboard 
- 修改自Xboard源项目地址：https://github.com/cedar2025/Xboard
- 这里是二次修改版本，去掉了对MySQL的支持，仅PostgreSQL（推荐）/sqlite
- 完美适用1Panel面板docker容器部署（面板中自行安装好Redis、PostgreSQL、
OpenResty）因为1Panel就是可视化的容器面板，镜像没打包Redis和PostgreSQL主要是我个人习惯用1Panel本地安装，不影响其它在跑的项目
- 同理支持aapanel宝塔面板+docker部署，大差不差一样的部署方式


# 更新
- 几个数据库表字段对postgresql的适配修复
- 新增易支付Pro支付方式插件（易支付最新Pro版本v1接口）
- 新增Epusdt（0.0.3仅usdt支付方式版本）插件
- 等......没什么大改动，主要是对postgresql数据库的支持（原版使用postgresql会有字段类型不兼容的错误问题）

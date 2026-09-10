ClassBase.define({
	init: function (param) {
        this.dgTpl = param.dgTpl;
        this.iconList = {
            win: 'ri-windows-fill win',
            mac: 'ri-apple-fill mac',
            // linux: 'linux',
            linux: 'ri-centos-fill linux',
            ios: 'ri-apple-fill ios',
            android: 'ri-android-fill android',
        };
    }, 

    // 个人菜单、登录页追加客户端下载链接
    initView: function(param){
        var _this = param.parent;
        var type = param.type;
        // 追加下载入口
        if (type == 'menu' && !_this.$('.menuBar .menu-dropdown-user .client-download').length) {
            var html = '<li class="client-download ripple-item" target="_blank">\
                            <i class="font-icon ri-download-fill-2"></i>\
                            '+LNG['client.down.client']+'\
                        </li>';
            _this.$('.menuBar .menu-dropdown-user li.copyright-show').after(html);
        }
        if (type == 'login' && !_this.$('.login-form form .client-download').length) {
            var html = '<span class="client-download">\
                            <a class="url-link" href="javascript:void(0);">'+LNG['client.down.client']+'</a>\
                        </span>';
            _this.$('.login-form form').append(html);
        }
        // 绑定事件
        var self = this;
        _this.$el.delegate('.client-download','click',function(e){
            self.showDialog();
        });
        _this.on('onRemove', function(){
            var dgs = $.dialog.list;
            _.each(dgs,function(dialog){
                if (dialog && (dialog.$main.hasClass('dialog-client-download') || dialog.$main.hasClass('client-down-qrcode-dg'))) {
                    dialog.close();
                }
            });
        });
        Events.trigger('client.down.link.loaded',_this,type);   // 菜单链接
    },

    // 显示下载窗口
    showDialog: function(){
		var dialog = $.dialog({
            id:"dialog-client-download",
			bottom:0,right:0,
			simple:true,
			resize:false,
			disableTab:true,
			className:"dialog-blur",
			title:LNG['client.down.client'],
			width:425,
			padding:0,
			fixed:true,
			content:this.renderHtml(this.dgTpl, false, false)
		});
        dialog.$main.addClass('dialog-copyright');
        this.ajaxAppList(dialog.$main);
        this.dialogEvent(dialog.$main);
	},

    // 通过接口获取应用列表
    ajaxAppList: function($dialog) {
        var self = this;
        var key  = 'kodbox.client.link';
        var result = LocalData.get(key);
            result = jsonDecode(result);
        if (result && result.time && result.time > time()) {
            return self.makeFormView($dialog,result);
        }
        if (!navigator.onLine) {
            return self.makeFormView($dialog,{code: false});
        }
        var tips = Tips.loadingMask();
        $.ajax({
            url: 'https://api.kodcloud.com/?app/version',
            timeout: 5000,
            dataType:'jsonp',
            success:function(result){
                tips && tips.close();
                var timeout = 3600*2;
                if(!result || !result.data) timeout = 60*10;
                result.time = time()+timeout;  // 过期时间：正常2小时，失败10分钟
                LocalData.set(key, jsonEncode(result));
                self.makeFormView($dialog,result);
            },
            error: function () {
                tips && tips.close();
                var result = {code: false, time: time()+60*10};
                LocalData.set(key, jsonEncode(result));
                self.makeFormView($dialog,result);
            }
        });
    },

    // 根据列表生成界面
    makeFormView: function($dialog, result){
        if (!result) result = {code:false};
        if (!result.data) result.data = {};
        // 获取注入列表
        Events.trigger('client.down.dialog.loaded',$dialog,result);
        if (!result.code || !result.data) {
            var html = '<div class="info-alert info-alert-yellow mt-50 size14">'+LNG['client.down.apiErr']+'</div>';
            $dialog.find('.k-content').html(html);
            return;
        }
        // 重新构造数据结构，并加载视图
        var list    = result.data;
        var pcList  = {win:[], mac: [], linux: []};
        var appList = {ios:[], android: []};
        _.each(pcList, function(items, app){
            var opt = _.get(list, app, null);
            if (_.isEmpty(opt)) return true;
            if (_.isArray(opt)) {
                pcList[app] = opt;  // items = opt;
            } else if (_.isObject(opt)) {
                if (app == 'mac' && _.get(opt, 'more')) {
                    var item1 = _.omit(opt, ['more']);
                    item1.type = 'Intel '+LNG['client.down.chip'];
                    items.push(item1);
                    if (_.isArray(opt.more) && !_.isEmpty(opt.more)) {
                        opt.more[0].type = 'Apple '+LNG['client.down.chip'];
                        items.push(opt.more[0]);
                    }
                } else {
                    items.push(opt);
                }
            }
        });
        this.makePcView($dialog, pcList);
        _.each(appList, function(items, app){
            var opt = _.get(list, app, null);
            if (_.isArray(opt)) opt = opt[0];   // 数组只取第一个
            if (_.isEmpty(opt)) return true;
            if (_.isObject(opt) && opt.link) {
                appList[app] = opt;
            }
        });
        this.makeAppView($dialog, appList);

        // 所有下载项为空则提示
        var pcNull  = this.isEmpty(pcList);
        var appNull = this.isEmpty(appList);
        if (pcNull && appNull) {
            Tips.tips(LNG['client.down.noApps'], 'warning');
        } else {
            var tab = pcNull ? 'pc' : (appNull ? 'app' : '');
            if (tab) $dialog.find('.tabs .tab[data-tab="'+tab+'"]').hide(); // 单类型不存在时隐藏切换入口
        }
    },

    // 生成客户端页面
    makePcView: function($dialog, pcList){
        var self = this;
        // if (!this.isEmpty(pcList)) {
        //     $dialog.find('#panel-pc .grid').empty();
        // }
        _.each(pcList, function(items, app){
            var nums = items.length;
            if (!nums) return true;
            var name = app == 'win' ? 'Windows' : _.upperFirst(app);

            var overlayHtml;
            if (nums == 1) {
                overlayHtml = '<div class="ov-group-label">' + name + '</div>'
                    + '<div class="ov-dl"><i class="font-icon ri-download-line"></i></div>'
                    + '<div class="ov-ver">' + (self.getVer(items[0].version) || '&nbsp;') + '</div>'
                    + '<div class="ov-sys">' + (items[0].desc || name) + '</div>';
            } else {
                overlayHtml = '<div class="ov-group-label">' + name + '</div>'
                    + '<div class="ov-count">' + nums + '</div>'
                    + '<div class="ov-more">'+LNG['client.down.manyVers']+'</div>';
            }
            // var icon = '<i class="font-icon '+self.iconList[app]+'">'+(app == 'linux' ? name : '')+'</i>';  // text
            var icon = '<i class="font-icon '+self.iconList[app]+'"></i>';
            var text = nums == 1 ? items[0].name : '';
            if (!text) text = name + LNG['explorer.toolbar.client'];
            if (nums == 1) {
                text += ' '+ self.getVer(items[0].version);
                text += items[0].desc ? '<br/>- ' + items[0].desc : '';
                text += items[0].size ? '<br/>- ' + items[0].size : '';
            } else {
                text += '<br/>- '+ _.replace(LNG['client.down.moreList'],'[0]',nums);
            }
            var $item = $('<div class="item" role="button" tabindex="0">'
                + '<div class="icon-frame"><div class="ic">' + icon + '</div></div>'
                + '<div class="item-name">' + name + '</div>'
                + '<div class="overlay" title="'+text+'" title-timeout="500">' + overlayHtml + '</div>'
                + '</div>');

            // 绑定事件
            if (nums == 1) {
                $item.on('click', function(){ 
                    window.open(items[0].link); // $.downloadFile(link)，link为页面链接时可能出现异常
                });
            } else {
                $item.on('click', function(){ 
                    self.makePcViewSub($dialog, name, icon, items)
                });
            }
            $dialog.find('#panel-pc .grid').append($item);
        });
    },
    // 生成客户端子页面
    makePcViewSub: function($dialog, name, icon, items){
        var self = this;
        // $dialog.find('.sub-title').text(name);
        $dialog.find('.sub-icon').html('<div class="ic">' + icon + '</div>');

        // 获取架构类型
        var getArch = function(link){
            var filename = pathTools.pathThis(urlDecode(link));
            if (!filename) return null;
            var match = filename.match(/(x86_64|aarch64|amd64|arm64|i386|i686)/i);
            return match ? match[0] : null;
        }
        // 子应用列表
        var $list = $dialog.find('.sub-list').empty();
        _.each(items, function(item, app){
            var type = item.type || ''
            var arch = getArch(item.link) || '';
            var $item = $(
                '<div class="sub-ver-item">'
                + '<div class="sub-ver-info">'
                +   '<div class="sub-ver-name">' + (type || item.name || arch) + ' ' + self.getVer(item.version) + '</div>'
                +   '<div class="sub-ver-detail">' + name + ' · ' + (arch || item.desc || '') + ' ' + (item.size || '') + '</div>'
                + '</div>'
                + '<button class="sub-ver-dl"><i class="font-icon ri-download-line"></i> '+LNG['common.download']+'</button>'
                + '</div>');
            $item.find('button').on('click', function(){
                window.open(item.link);
            });
            $list.append($item);
        });

        $dialog.find('#panel-pc .grid').hide();
        $dialog.find('.sub-view').addClass('active');
    },

    // 生成app页面
    makeAppView: function($dialog, appList){
        var self = this;
        // 显示二维码弹窗
        var showQrcode = function(app,info){
            var dg = core.qrcode(info.link);
			if (!dg || !dg.$main) {
				return Tips.tips(LNG['client.tfa.tryRefresh'], 'warning');
			}
            var icon = '<i class="font-icon '+self.iconList[app]+'"></i>';
            var header = '<div>\
                        <div class="icon">'+icon+'</div>\
                        <div class="name">'+(app == 'ios' ? 'iOS' : 'Android')+'</div>\
                        <div class="desc">'+self.getVer(info.version)+'</div>\
                        </div>';
            var footer = '<div class="desc">'+LNG['client.down.webScan']+'</div>';
            dg.title(LNG['client.down.appDown']).content('<div  class="qrcode-box">' + (header + dg.config.content + footer) + '</div>', true).button([{
				name: LNG['client.down.clickDown'],
				className:'aui-state-highlight',
				callback: function() {
                    window.open(info.link);
                    return false;
				}
			}]);
            dg.$main.addClass('client-down-qrdialog');
        }
        // app视图列表
        // if (!this.isEmpty(appList)) {
        //     $dialog.find('#panel-app .grid').empty();
        // }
        _.each(appList, function(item, app){
            if (_.isEmpty(item)) return true;
            var icon = '<i class="font-icon '+self.iconList[app]+'"></i>';
            var name = app == 'ios' ? 'iOS' : _.upperFirst(app);
            var text = item.name || (name + ' APP');
                text += ' ' + self.getVer(item.version);
                text += item.desc ? '<br/>- ' + item.desc : '';
                text += item.size ? '<br/>- ' + item.size : '';
            var $item = $('<div class="item" role="button" tabindex="0">'
                + '<div class="icon-frame"><div class="ic">' + icon + '</div></div>'
                + '<div class="item-name">' + name + '</div>'
                + '<div class="overlay" title="'+text+'" title-timeout="500">'
                +   '<div class="ov-group-label">' + name + '</div>'
                +   '<div class="ov-dl"><i class="font-icon ri-download-line"></i></div>'
                +   '<div class="ov-ver">' + (self.getVer(item.version) || '&nbsp;') + '</div>'
                +   '<div class="ov-sys">' + (item.desc || name) + '</div>'
                + '</div>'
                + '</div>');

            // 点击触发自定义事件，由外部监听处理（如弹出二维码弹窗）
            $item.on('click', function(){
                showQrcode(app,item);
            });
            $dialog.find('#panel-app .grid').append($item);
        });
    },
    getVer: function(version){
        return version ? (_.startsWith(version,'v') ? version : 'v'+version) : '';
    },
    isEmpty: function(obj){
        return _.every(obj, function(value){
            return _.isEmpty(value);
        });
    },

    dialogEvent: function($dialog){
        // tab切换
        $dialog.delegate('.tabs .tab', 'click', function(){
            var target = $(this).data('tab');
            $dialog.find('.tab').removeClass('active');
            $(this).addClass('active');
            $dialog.find('.tab-panel').removeClass('active');
            var $panel = $dialog.find('#panel-' + target);
            $panel.addClass('active');
            // 切回 PC 时恢复主视图
            if (target === 'pc') {
                $panel.find('.sub-view').removeClass('active');
                $panel.find('.grid').show();
            }
        });
        // 子列表页返回
        $dialog.find('.sub-back').on('click', function(){
            $dialog.find('#panel-pc .grid').show();
            $dialog.find('.sub-view').removeClass('active');
        });
    },

});
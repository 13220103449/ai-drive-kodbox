ClassBase.define({
	init: function (param) {
		this.initView();
	},
	initView: function () {
		var self = this;
        this.ggRequest({action: 'bindInfo'}, function (result) {
			if (!result || !result.code) return;
			self.renderRow(result.data.isBind);
		});
	},
	renderRow: function (isBind) {
		var self = this;
		var title = LNG['client.tfa.totp'];
		var content = isBind ? LNG['user.binded'] : '<a class="bind-tfa" href="javascript:void(0)">' + LNG['user.clickBind'] + '</a>';
		var action = isBind ? '<span class="col-action"><a class="unbind-tfa" href="javascript:void(0)">' + LNG['user.unbind'] + '</a></span>' : '';

		var html = '<div class="acc-row item-tfa-totp">' +
                        '<span class="col-title">' +
                            '<i class="font-icon ri-shield-user-line"></i>' +
                            '<span>' + title + '</span>' +
                        '</span>' +
                        '<span class="col-content">' + content + '</span>' + action +
                    '</div>';
		var $pwdRow = this.$('.account-page .user-set .form-row.item-change-password');
		this.$('.item-tfa-totp').remove();
		if ($pwdRow.length) {
			$pwdRow.after(html);
		} else {
			this.$('.account-page .user-set').append(html);
		}
		this.bindEvent();
	},
	bindEvent: function () {
		var self = this;
        // 去掉点击事件——默认展开导致隐藏
		this.$('.item-tfa-totp').unbind('click').bind('click', function (e) {
			stopPP(e);
		});
        // 绑定
		this.$('.item-tfa-totp .bind-tfa').unbind('click').bind('click', function (e) {
			stopPP(e);
			self.showBindDialog();
		});
        // 解绑
		this.$('.item-tfa-totp .unbind-tfa').unbind('click').bind('click', function (e) {
			stopPP(e);
			$.dialog.confirm(LNG['user.ifUnbind'], function () {
                self.ggRequest({action: 'unbind'}, function (result) {
                    Tips.close(result);
					if (result.code) self.initView();
                });
			});
		});
	},
	showBindDialog: function () {
		var self = this;
		this.ggRequest({action: 'initInfo'}, function (result) {
			if (!result || !result.code) return Tips.tips(result);
			var data = result.data;

            var dg = core.qrcode(data.qrCodeUri);
			if (!dg || !dg.$main) {
				return Tips.tips(LNG['client.tfa.tryRefresh'], 'warning');
			}
            var html = '<div class="form-box"><div class="form-row">\
                            <div class="mt-10 mb-10 align-center grey-8">'+LNG['client.tfa.totpDesc']+'</div>\
                            <div class="align-center"><input type="text" name="code" placeholder="'+LNG['user.inputVerifyCode']+'" class="form-input-text align-center" style="width:200px;margin-right:0px;"></div>\
                        </div></div>';
            dg.title(LNG['client.tfa.bindTotp']).content(dg.config.content+html, true).button([{
				name: LNG['common.ok'],
				className:'aui-state-highlight',
				callback: function() {
                    var $input = dg.$main.find('input[name="code"]');
                    var code = $.trim($input.val());
					if (!code) {
						$input.focus();
						return false;
					}
					self.ggRequest({action: 'bind', input: data.secret, code: code}, function (res) {
						Tips.close(res);
						if (res.code) {
							self.initView();
                            dg && dg.close();
						}
					});
					return false;
				}
			}]);
			dg.$main.find('.aui-content a').attr('href','javascript:void(0)').removeAttr('target');
            return false;
		});
	},

    ggRequest: function(data, callback){
        data.type = 'totp';
        kodApi.requestSend('plugin/client/tfa', data, function(result){
            callback(result);
        });
    }
});
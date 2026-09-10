ClassBase.define({
	init: function (param) {
        this.$el = this.parent.$('.login-form');
        this.checkInput = _.get(this,'checkInput.check');
        if (typeof _.get(this,'checkInput.check') != 'function') this.checkInput = false;
    },

    // 显示二次验证窗口
    tfaShow: function(tfaInfo){
        this.tfaSign = _.get(tfaInfo,'sign','');

        // 去除登录页加载样式
        NProgress.done();
        $("body .loading-msg-mask").remove();
        this.$('.submit-button.login').removeClass('disable-event');

        // 获取表单数据
        var tfaType  = _.get(tfaInfo,'tfaType') || '';
        var typeArr  = _.split(tfaType,',');
        var formData = _.cloneDeep(this.formData());
        formData.tfaType.info = _.pick(formData.tfaType.info, typeArr);
        formData.tfaType.value = typeArr[0];
        _.each(typeArr, function(type){
            var value = _.get(tfaInfo, 'tfaList.'+type, '');
            if (value) {
                formData[type].className = 'no-input';
                if (type == 'totp') {
                    formData.totp.className = 'hidden';
                }
                value = LNG['client.tfa.sendTo'] + ' ' + value;
            }
            formData[type].value = value;
        });

        var option = {
            className:"user-login-tfa-dg",
            ico: '<i class="ri-lock-password-line orange-5 mr-5"></i>',
            title: LNG['client.tfa.2verify'],
            width: 360,
            height: 200,
            okVal: LNG['common.ok']
        };
        var self = this;
        var form = new kodApi.formMaker({parent:this,formData:formData});
		form.renderDialog(option, function(data){
            var type = data.tfaType;
            var param = {
                type: type,
                input: data[type],
                code: data[type+'-code']
            };
            if (!param.code) {
                Tips.tips(LNG['user.inputVerifyCode'],'warning');
                return false;
            }
            self.doVerify(param);
            return false;
        });
        // google验证器未绑定时，显示绑定提示
        if (_.includes(typeArr, 'totp') && !formData.totp.value) {
            form.$('.item-totp .setting-content>*:not(.desc)').addClass('hidden');
        }

        // 手机/邮箱获取验证码
        form.$el.delegate('.input-title-right', 'click', function(){
            var $btn = $(this);
            // 检查
            var type = form.getValue('tfaType');
            var input = _.get(tfaInfo, 'tfaList.'+type, '');
            if (!input) {
                input = form.getValue(type);
                if (!input || !self.checkInput.check(input, type)) {
                    // form.$('.item-'+type+' input').select(); // 无效
                    var name = LNG['common.'+type];
                    Tips.tips(LNG['client.tfa.inputValid']+name,'warning');
                    return false;
                }
            }
            var data = {type: type, input: input, action: 'tfaCode'};
            // 提交
            var tips = Tips.loadingMask();
            $btn.prop("disabled", true);
            self.tfaRequest(data, function(result){
                tips.close();
                Tips.close(result);
                if (!result.code) {
                    $btn.prop("disabled", false);
                    return false;
                }
                self.sendAfter(data.type, $btn);
            });
        });
        // 绑定谷歌验证器
        form.$el.delegate('.item-totp a.bind', 'click', function(){
            self.bindTotp(form, this);
        });
    },
    formData: function(){
        return {
            'formStyle': {className: "form-box-title-block"},
            'tfaType': {
                type: 'segment',
                value: '',
                // display: '验证方式',
                info: {
                    phone: LNG['common.sms'],
                    email: LNG['common.email'],
                    totp:  LNG['client.tfa.totp']
                },
                switchItem: {
                    phone: 'phone,phone-code',
                    email: 'email,email-code',
                    totp:'totp,totp-code'
                }
            },
            'phone':{
                type:'input',
                value:'',
				desc: '',
                attr:{"placeholder":LNG['user.inputPhone']},
                className: ''
            },
            'phone-code':{
                type:'input',
                value:'',
				desc: '',
                attr:{"placeholder":LNG['user.inputSmsCode']},
                titleRight:LNG['user.getCode']
            },
            'email':{
                type:'input',
                value:'',
				desc: '',
                attr:{"placeholder":LNG['user.inputEmail']},
                className: ''
            },
            'email-code':{
                type:'input',
                value:'',
				desc: '',
                attr:{"placeholder":LNG['user.inputEmailCode']},
				titleRight:LNG['user.getCode']
            },
            'totp':{
                type:'input',
                value:'',
				desc: '<div class="qrcode-box">'+LNG['user.notBind']+', <a href="javascript:void(0)" class="bind">'+LNG['user.bind']+'</a></div>',
            },
            'totp-code':{
                type:'input',
                value:'',
                attr:{"placeholder":LNG['client.tfa.totpCode']},
				desc: '',
				// className:"hidden"
            },
		};
    },

    // 绑定谷歌验证器
    bindTotp: function(form, e){
        this.tfaRequest({type: 'totp', action: 'initInfo'}, function (result) {
            if (!result || !result.code) return Tips.tips(result);
            var data = result.data;
            // 生成二维码
            var image = API_URL('user/view/qrcode','url='+quoteHtml(urlEncode(data.qrCodeUri)));
            $(e).parent().html('<img src="'+image+'" /><div class="mt-10 mb-5 align-center grey-8">'+LNG['client.tfa.totpDesc']+'</div>');
            form.dialog.size(form.dialog._width, 400);

            form.setValue('totp', data.secret);
        });
    },

    sendAfter: function (type, $button) {
        // 发送成功,button倒计时
        var time = type == 'email' ? 60 : 90;
        $button.text(time + 's');
        var timer = setInterval(function () {
            if (time > 0) {
                time--;
                $button.text(time + 's');
            } else {
                $button.text(LNG['user.getCode']);
                $button.prop("disabled", false);
                clearInterval(timer);
            }
        }, 1000);
    },

    // 提交二次验证/更新登录状态
    doVerify: function (data) {
        var self = this;
        var tips = Tips.loadingMask();
        data.action = 'tfaVerify';
        this.tfaRequest(data, function(result){
            tips && tips.close();
            Tips.close(result);
            if (!result.code) return false;
            self.dialog && self.dialog.close();
            if (_.get(self,'parent.loginSuccess')) {
                self.parent.withTfa = true;
                self.parent.loginSuccess();
            } else {
                var link = _.get(Router,'queryBefore.link') || G.kod.APP_HOST;
                window.location.href = link;
            }
            return false;
        });
    },

    tfaRequest: function(data, callback){
        data.sign = this.tfaSign;
        kodApi.requestSend('plugin/client/tfa', data, function(result){
            callback(result);
        });
    }
});
<?php
namespace Dori\Bill99\Services;

class Bill99Service
{
    /*配置名称*/
    protected $config_name = 'pay.bill99';
    /*支付类型*/
    protected $payment_method = '快钱支付';
    /*支付类型标识*/
    protected $payment_method_code = 'bill99';
    /*form提交参数*/
    private $form_params;
    /*form提交地址*/
    private $form_action;
    /*加密证书*/
    private $file_cert_pem;
    /*解密证书*/
    private $file_cert_cer;
    protected $config;
    protected $orders_number;
    protected $email_address;
    protected $total_price;
    protected $product_desc;

    public function __construct(array $config)
    {
        $this->config = $config;
//        parent::__construct();
        /*参数初始化*/
        self::getDefaultParameters();
    }

    /***
     * @note:初始化默认参数
     *
     * @author:paul
     * @date:Times
     */
    private function getDefaultParameters()
    {
        $url = [
            'dev' =>'https://sandbox.99bill.com/gateway/recvMerchantInfoAction.htm',
            'normal' => 'https://www.99bill.com/gateway/recvMerchantInfoAction.htm'
        ];
        $this ->form_action = $url[$this ->config['mode']];
        $this ->file_cert_pem = $this ->config['file_cert_pem'];
        $this ->file_cert_cer = $this ->config['file_cert_cer'];
        $this->form_params = [
            'inputCharset'     => $this->config['inputCharset'],
            'pageUrl'          => $this->config['pageUrl'] ?? '',
            'bgUrl'            => $this->config['bgUrl'] ?? '',
            'version'          => $this->config['version'],
            'language'         => $this->config['language'],
            'signType'         => $this->config['signType'],
            'mobileGateway'    => $this ->config['mobileGateway'] ?? '',
            'merchantAcctId'   => $this ->config['merchantAcctId'],
            'payType'          => $this ->config['payType'],
        ];
    }

    /**
     * @Author: dori
     * @Date: 2022/9/21
     * @Descrip:发起支付请求
     * @param $orders_number
     * @param $email_address
     * @param $total_price
     * @param $product_desc
     * @return array|string
     */
    public function logicHandle($orders_number,$email_address,$total_price,$product_desc)
    {
        $this ->orders_number = $orders_number;
        $this ->email_address = $email_address;
        $this ->total_price = $total_price;
        $this ->product_desc = $product_desc;
//        $this ->order = $order;
        //构建支付参数
        self::buildRequest();
        //执行重定向提交跳转
        return self::buildRequestStr();
    }


    /***
     * @note:异步回调处理请求(bill99 里面是同步通知)
     *
     * @author:paul
     * @date:Times
     * @param Request $request
     * @return bool
     */
    public function notify()
    {
        $bill99 = $_REQUEST;
        $params = self::sortVerifyRequest($bill99);
        $kq_check_all_para = '';
        foreach ($params as $key => $val) {
            $kq_check_all_para .= self::kqckLinkNull($val, $key);
        }
        $trans_body=substr($kq_check_all_para, 0, strlen($kq_check_all_para)-1);
        $MAC=base64_decode($bill99['signMsg']);
        $fp = fopen($this->file_cert_cer, "r");
        $cert = fread($fp, 8192);
        fclose($fp);
        $pubkeyid = openssl_get_publickey($cert);
        if ($this ->config['mode'] == 'dev') {
            $result = openssl_verify($trans_body, $MAC, $pubkeyid, OPENSSL_ALGO_SHA256);
        } else {
            $result = openssl_verify($trans_body, $MAC, $pubkeyid);
        }
        if ($result && $bill99['payResult'] == 10) { //回调成功
            //业务逻辑处理
//            self::processing($params['orderId'], 1, $params);
//            parent::dblogContro('bill99', $params['orderId'], 'bill99 notify success', $params);
            //parent::logContro('bill99 notify success --'.$params['orderId'], $bill99);
        }
        //给服务器那边通知结束
        echo self::redirect($bill99['payResult'] ?? 0, $params['orderId'] ?? '');
        exit();
    }

    /***
     * @note:bill99 跳转
     * @author:paul
     * @date:Times
     */
    public function redirect($resultCode, $out_trade_no = '')
    {
        $resultState = 0;
        $location = config('app.url');
        if ($resultCode == 10) { //执行成功
            $orders_number = trim(substr($out_trade_no, 0, -6));
            $orderInfo = DB::table('orders')
                ->where('orders_number', $orders_number)
                ->select(['orders_id'])
                ->first();
            if ($orderInfo) {
                $location = config('app.url').'/user/pay?orders_id='.
                    $orderInfo->orders_id.
                    '&pay_status=success&payment=bill99';
            }
            $resultState = 1;
        }
        //确定成功的返回
        return '<result>'.$resultState.'</result> <redirecturl>'.$location.'</redirecturl>';
    }

    /***
     * @note:执行重定提交请求
     *
     * @author:paul
     * @date:2019/10/29 19:01
     * @return array
     */
    private function buildRequestStr()
    {
        /**$params = $this ->form_params;
        $outStr = '<!DOCTYPE html>
        <html>
        <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>Redirecting...</title>
        </head><body onload="document.forms[0].submit();">';
        $outStr .= '<form action="'.$this->form_action.'" method="post">';
        foreach ($params as $key => $val) {
        $outStr .= '<input type="hidden" name="'.$key.'" value="'.$val.'" />';
        }
        $outStr .= '<input type="submit" style="display: none" value="提交到快钱"></form></body></html>';
        echo $outStr;die();**/

        $outStr['scalar'] = $this ->form_action.'?'.http_build_query($this ->form_params);

//        $orders_id = $this ->order->info['orders_id'];
//        parent::dblogContro(
//            'bill99',
//            $this ->form_params['orderId'],
//            'bill99 buildRequestStr',
//            $outStr['scalar'],
//            $orders_id
//        );

        return $outStr;
    }

    /***
     * @note:支付业务参数构建
     *
     * @author:paul
     * @date:Times
     */
    private function buildRequest()
    {
        $kq_all_para = '';
        $this ->form_params['orderId'] = $this ->orders_number.date('His');
        $this ->form_params['orderTime'] = date("YmdHis");
        $this ->form_params['orderTimestamp'] = date("YmdHis");
        $this ->form_params['payerContactType'] = 1;
        $this ->form_params['payerContact'] = $this ->email_address;
        $this ->form_params['productDesc'] = $this->product_desc;
        $this ->form_params['orderAmount'] = preg_replace(
                '/\￥|\s|\&nbsp;|\,/',
                '',
                $this ->total_price
            )*100;
        //测试金额
        //$this ->form_params['orderAmount'] = 1;
        $this ->form_params['productName'] = 'FS.COM产品';
        $this ->form_params = self::sortRequest($this->form_params);
        foreach ($this->form_params as $key => $val) {
            $kq_all_para .= self::kqckLinkNull($val, $key);
        }
        $kq_all_para=substr($kq_all_para, 0, strlen($kq_all_para)-1);
        //排序
        if ($this ->config['mode'] == 'dev') {
            $sign = self::getDevSign($kq_all_para);
        } else {
            $sign = self::getSign($kq_all_para);
        }
        $this ->form_params['signMsg'] = $sign;
    }

    /***
     * @note:拼接链接信息
     *
     * @author:paul
     * @date:Times
     * @param $kq_va
     * @param $kq_na
     * @return string
     */
    private function kqckLinkNull($kq_va, $kq_na)
    {
        if ($kq_va == "") {
            return "";
        } else {
            return $kq_na.'='.$kq_va.'&';
        }
    }

    /***
     * @note:获取签名
     *
     * @author:paul
     * @date:2019/10/29 9:50
     * @param $kq_all_para
     * @return string
     */
    private function getSign($kq_all_para)
    {
        //Rsa 签名计算
        $fp = fopen($this->file_cert_pem, "r");
        $priv_key = fread($fp, 123456);
        fclose($fp);
        $pkeyid = openssl_get_privatekey($priv_key);
        // compute signature
        openssl_sign($kq_all_para, $signMsg, $pkeyid, OPENSSL_ALGO_SHA1);
        // free the key from memory
        openssl_free_key($pkeyid);
        $signMsg = base64_encode($signMsg);
        return $signMsg;
    }


    /***
     * @note:沙箱测试的签名的方法
     *
     * @author:paul
     * @date:Times
     * @param $kq_all_para
     * @return string
     */
    private function getDevSign($kq_all_para)
    {
        //沙箱的签名测试
        $pfx=file_get_contents($this->file_cert_pem); //商户PFX证书地址
        $key_password = '123456';//证书密码

        openssl_pkcs12_read($pfx, $certs, $key_password);
        $privkey=$certs['pkey'];

        openssl_sign($kq_all_para, $signMsg, $privkey, OPENSSL_ALGO_SHA256);

        $signMsg = base64_encode($signMsg);
        return $signMsg;
    }

    /***
     * @note:加密解密参数排序
     *
     * @author:paul
     * @date:2019/10/29 9:50
     * @param $params
     * @return array
     */
    private function sortRequest($params)
    {
        $outArr = [
            'inputCharset'     => $params['inputCharset'],
            'pageUrl'          => $params['pageUrl'] ?? '',
            'bgUrl'            => $params['bgUrl'] ?? '',
            'version'          => $params['version'],
            'language'         => $params['language'],
            'signType'         => $params['signType'],
            'signMsg'          => $params['signMsg'] ?? '',
            'merchantAcctId'   => $params['merchantAcctId'] ?? '',
            'payerName'        => $params['payerName'] ?? '',
            'payerContactType' => $params['payerContactType'] ?? '',
            'payerContact'     => $params['payerContact'] ?? '',
            'payerIdType'      => $params['payerIdType'] ?? '',
            'payerId'          => $params['payerId'] ?? '',
            'payerIP'          => $params['payerIP'] ?? '',
            'orderId'          => $params['orderId'] ?? '',
            'orderAmount'      => $params['orderAmount'] ?? 0,
            'orderTime'        => $params['orderTime'] ?? '',
            'orderTimestamp'   => $params['orderTimestamp'] ?? '',
            'productName'      => $params['productName'] ?? '',
            'productNum'       => $params['productNum'] ?? '',
            'productId'        => $params['productId'] ?? '',
            'productDesc'      => $params['productDesc'] ?? '',
            'ext1'             => $params['ext1'] ?? '',
            'ext2'             => $params['ext2'] ?? '',
            'payType'          => $params['payType'] ?? '',
            'bankId'           => $params['bankId'] ?? '',
            'cardIssuer'       => $params['cardIssuer'] ?? '',
            'cardNum'          => $params['cardNum'] ?? '',
            'remitType'        => $params['remitType'] ?? '',
            'remitCode'        => $params['remitCode'] ?? '',
            'redoFlag'         => $params['redoFlag'] ?? '',
            'pid'              => $params['pid'] ?? '',
            'submitType'       => $params['submitType'] ?? '',
            'orderTimeOut'     => $params['orderTimeOut'] ?? '',
            'extDataType'      => $params['extDataType'] ?? '',
            'extDataContent'   => $params['extDataContent'] ?? '',
        ];

        foreach ($outArr as $key => $val) {
            if (empty($val)) {
                unset($outArr[$key]);
            }
        }
        return $outArr;
    }


    /***
     * @note:数据解密验签排序
     *
     * @author:paul
     * @date:Times
     * @param $params
     * @return array
     */
    private function sortVerifyRequest($params)
    {
        $outArr = [
            'merchantAcctId'   => $params['merchantAcctId'] ?? '',
            'version'          => $params['version'],
            'language'         => $params['language'],
            'signType'         => $params['signType'],
            'payType'          => $params['payType'] ?? '',
            'bankId'           => $params['bankId'] ?? '',
            'orderId'          => $params['orderId'] ?? '',
            'orderTime'        => $params['orderTime'] ?? '',
            'orderAmount'      => $params['orderAmount'] ?? 0,
            'bindCard'         => $params['bindCard'] ?? '',
            'bindMobile'       => $params['bindMobile'] ?? '',
            'dealId'           => $params['dealId'] ?? '',
            'bankDealId'       => $params['bankDealId'] ?? '',
            'dealTime'         => $params['dealTime'] ?? '',
            'payAmount'        => $params['payAmount'] ?? '',
            'fee'              => $params['fee'] ?? '',
            'ext1'             => $params['ext1'] ?? '',
            'ext2'             => $params['ext2'] ?? '',
            'payResult'        => $params['payResult'] ?? '',
            'aggregatePay'     => $params['aggregatePay'] ?? '',
            'errCode'          => $params['errCode'] ?? '',
        ];

        foreach ($outArr as $key => $val) {
            if (empty($val)) {
                unset($outArr[$key]);
            }
        }
        return $outArr;
    }
}

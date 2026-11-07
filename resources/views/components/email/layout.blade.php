{{-- Shared shell for every WedWorks email. Structure and inline styles copied from docs/WedWorks_Email_Templates.html. --}}
@props(['subject', 'preheader' => ''])
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;600;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  table,td{mso-table-lspace:0;mso-table-rspace:0}
  body{margin:0!important;padding:0!important;width:100%!important;background:#f8fafc}
  a{color:#2f5bea}
  @media only screen and (max-width:620px){
    .outer{padding:12px 8px!important}
    .px{padding-left:20px!important;padding-right:20px!important}
    .hd{padding:18px 20px!important}
    .h1{font-size:22px!important;line-height:28px!important}
    .amount{font-size:34px!important;line-height:42px!important}
    .btn table{width:100%!important}
    .btn a{display:block!important}
    .rk,.rv{padding-left:14px!important;padding-right:14px!important}
  }
</style>
</head>
<body style="margin:0;padding:0;background:#f8fafc">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f8fafc">{{ $preheader }}&#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; </div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="background:#f8fafc">
<tr><td class="outer" align="center" style="padding:32px 16px">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
  <tr><td class="hd" bgcolor="#2f5bea" style="background:#2f5bea;border-radius:20px 20px 0 0;padding:22px 32px">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
      <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background:#ffffff;color:#2f5bea;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;line-height:40px">W</td>
      <td style="padding-left:12px">
        <div style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:19px;font-weight:700;line-height:22px;color:#ffffff;letter-spacing:-0.3px">WedWorks</div>
        <div style="font-family:'Inter',Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#d6e0fc">Kasalang Tagaytay &middot; 10-10-10 Campaign</div>
      </td>
    </tr></table>
  </td></tr>
  <tr><td class="px" bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 20px 20px;padding:32px 36px 36px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
{{ $slot }}
<x-email.spacer :h="28" />
<tr><td style="border-top:1px solid #e2e8f0;font-size:0;line-height:0">&nbsp;</td></tr>
<x-email.spacer :h="20" />
<tr><td style="font-family:'Inter',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#64748b">Questions? Just reply to this email and our team will help.<br>&mdash; The Kasalang Tagaytay Team</td></tr>
    </table>
  </td></tr>
  <tr><td align="center" style="padding:22px 24px 8px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;text-align:center">
    You&rsquo;re receiving this because you registered for the 10-10-10 Campaign at Kasalang Tagaytay.<br>We only contact you by email.<br><br>
    Kasalang Tagaytay &middot; Tagaytay City, Philippines
  </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>

@php
    $balance = $invoice->balanceCents();
    $paid = $invoice->total_cents - $balance;
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoice {{ $invoice->number }}</title></head>
<body style="margin:0;padding:0;background:#f3f5ef;color:#233a35;font-family:Arial,Helvetica,sans-serif">
<div style="display:none;max-height:0;overflow:hidden">Invoice #{{ $invoice->number }} &middot; {{ \App\Support\Money::format($balance) }} remaining &middot; Due {{ $invoice->due_on->format('F j, Y') }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5ef"><tr><td align="center" style="padding:32px 12px">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dfe6dc;border-radius:16px;overflow:hidden">
<tr><td style="background:#244f42;padding:30px 28px;color:#ffffff"><p style="margin:0 0 8px;font-size:12px;letter-spacing:2px;color:#d4e5b6">1262 BRYN MAWR ASSOCIATION</p><h1 style="margin:0;font-size:30px;line-height:1.2">A little condo paperwork.</h1><p style="margin:12px 0 0;font-size:15px;color:#e5edde">Unit {{ $invoice->unit_id }} &middot; Invoice #{{ $invoice->number }}</p></td></tr>
<tr><td style="padding:28px"><p style="margin:0 0 12px;font-size:16px;line-height:1.6">Hi {{ $resident->name }},</p><p style="margin:0 0 24px;font-size:16px;line-height:1.6">{{ $balance > 0 ? 'Here are the details for your association invoice. Thanks for keeping our five-unit operation humming.' : 'This invoice is paid in full. No payment needed. The paperwork is just here for the scrapbook.' }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4e5;border-radius:10px"><tr><td style="padding:22px"><p style="margin:0 0 8px;font-size:12px;font-weight:bold;letter-spacing:1px">{{ $balance > 0 ? 'AMOUNT REMAINING' : 'PAID IN FULL' }}</p><p style="margin:0;font-size:38px;font-weight:bold;line-height:1.2">{{ \App\Support\Money::format($balance) }}</p><p style="margin:10px 0 0;font-size:15px">Due {{ $invoice->due_on->format('F j, Y') }}</p></td></tr></table>
<p style="margin:20px 0;font-size:14px;color:#607269">Issued {{ $invoice->issued_on->format('M j, Y') }} &middot; {{ $invoice->household->client_name }}</p>
<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:15px"><thead><tr><th align="left" style="padding:12px 0;border-bottom:2px solid #e3e8e1">Description</th><th align="right" style="padding:12px 0;border-bottom:2px solid #e3e8e1">Amount</th></tr></thead><tbody>
@foreach($invoice->items as $item)<tr><td style="padding:15px 10px 15px 0;border-bottom:1px solid #e3e8e1;line-height:1.5">{{ $item['name'] }}@if(!empty($item['description']))<br><span style="font-size:13px;color:#607269">{{ $item['description'] }}</span>@endif</td><td align="right" style="padding:15px 0;border-bottom:1px solid #e3e8e1;white-space:nowrap;vertical-align:top">{{ \App\Support\Money::format($item['amount_cents']) }}</td></tr>@endforeach
<tr><td style="padding:16px 0 8px">Invoice total</td><td align="right" style="padding:16px 0 8px">{{ \App\Support\Money::format($invoice->total_cents) }}</td></tr>
@if($paid > 0)<tr><td style="padding:6px 0;color:#607269">Payments applied</td><td align="right" style="padding:6px 0;color:#607269">{{ \App\Support\Money::format($paid) }}</td></tr>@endif
</tbody></table>
<h2 style="margin:28px 0 12px;font-size:19px">How to pay</h2>
<div style="padding:18px;background:#f7f8f5;border:1px solid #e3e8e1;border-radius:8px;font-size:15px;line-height:1.7">{!! nl2br(e(config('association.payment_instructions'))) !!}</div>
<p style="margin:12px 0 26px;font-size:13px;color:#607269">Include your unit and invoice number with your payment. If you already paid, give us a moment to match it up.</p>
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td bgcolor="#244f42" style="border-radius:7px"><a href="{{ route('invoice', $invoice) }}" style="display:inline-block;padding:15px 22px;color:#ffffff;text-decoration:none;font-size:16px;font-weight:bold">View invoice in the portal &rarr;</a></td></tr></table>
<p style="margin:12px 0 0;color:#607269;font-size:12px">Sign in with your resident email to view the latest payment status.</p>
</td></tr>
<tr><td style="padding:22px 28px;border-top:1px solid #e3e8e1;font-size:12px;line-height:1.7;color:#607269">1262 Bryn Mawr Association<br>1262 W. Bryn Mawr Ave &middot; Chicago, IL 60660<br>Five units. Several opinions. One building.</td></tr>
</table></td></tr></table></body></html>

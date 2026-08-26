# -*- coding: utf-8 -*-
from _nav import nav, TOP, page

# ---------- 08 Profit / Loss ----------
def row(l,v,cls=""):
    return f'<tr><td>{l}</td><td class="r num {cls}">{v}</td></tr>'

PL = f'''{nav(pl=1)}<div class="main">{TOP}<div class="body">
<h1>Profit / Loss</h1><div class="crumb">Main branch · 01-08-2026 to 26-08-2026 · FIFO cost, frozen at sale time</div>
<div class="kpis">
<div class="kpi"><div class="l">Gross profit</div><div class="v num">৳ 402,180</div><div class="d">21.8% of net sales</div></div>
<div class="kpi"><div class="l">Net profit</div><div class="v num">৳ 311,540</div><div class="d up">▲ 8.1% vs July</div></div>
<div class="kpi"><div class="l">COGS</div><div class="v num">৳ 1,441,820</div><div class="d">FIFO across 1,284 batches</div></div>
<div class="kpi"><div class="l">Expenses</div><div class="v num">৳ 90,640</div><div class="d">6 categories</div></div>
</div>
<div class="two">
<div class="card"><div class="ch"><h2>Stock movement</h2></div>
<table><thead><tr><th></th><th class="r">At purchase price</th><th class="r">At sale price</th></tr></thead>
<tbody>
<tr><td>Opening stock (01-08)</td><td class="r num">৳ 1,180,400</td><td class="r num">৳ 1,438,000</td></tr>
<tr><td>Add: purchases</td><td class="r num">৳ 1,546,030</td><td class="r num">—</td></tr>
<tr><td>Less: purchase return</td><td class="r num">৳ 0</td><td class="r num">—</td></tr>
<tr><td>Less: stock adjustment (normal)</td><td class="r num">৳ 12,340</td><td class="r num">—</td></tr>
<tr><td>Less: stock adjustment (abnormal)</td><td class="r num">৳ 4,880</td><td class="r num">—</td></tr>
<tr><td>Transfer out (net)</td><td class="r num">৳ 0</td><td class="r num">—</td></tr>
<tr><td>Closing stock (26-08)</td><td class="r num">৳ 1,267,390</td><td class="r num">৳ 1,544,210</td></tr>
</tbody></table>
<div class="note">Closing stock is the ledger sum, not a cached figure — it rebuilds from stock moves.</div>
</div>
<div class="card"><div class="ch"><h2>The arithmetic, printed</h2></div>
<div class="cb">
<table><tbody>
{row("Net sales","৳ 1,844,000")}
{row("Cost of goods sold","৳ 1,441,820")}
{row("<b>Gross profit</b>","<b>৳ 402,180</b>")}
</tbody></table>
<div class="formula" style="margin:8px 0 16px">COGS = opening 1,180,400 + purchases 1,546,030 − adjustments 17,220 − closing 1,267,390 = 1,441,820<br>Gross profit = net sales 1,844,000 − COGS 1,441,820 = 402,180</div>
<table><tbody>
{row("Gross profit","৳ 402,180")}
{row("Less: total expenses","৳ 90,640")}
{row("Add: other income","৳ 0")}
{row("<b>Net profit</b>","<b>৳ 311,540</b>")}
</tbody></table>
<div class="formula" style="margin-top:8px">Net profit = gross profit 402,180 − expenses 90,640 + other income 0 = 311,540</div>
<div style="margin-top:14px;padding:10px 12px;background:#e6f6ec;border:1px solid #b8e2c6;border-radius:7px;font-size:12.5px;color:#0a7a2f">
<b>Sanity check passed.</b> COGS is positive and closing stock does not exceed opening plus purchases. A negative COGS is blocked and reported rather than printed.</div>
</div>
</div>
</div>
<div class="card"><div class="ch"><h2>Expense breakdown</h2></div>
<table><thead><tr><th>Category</th><th>Sub-category</th><th class="r">This period</th><th class="r">Last period</th><th class="r">Change</th></tr></thead>
<tbody>
<tr><td>Rent</td><td>Shop</td><td class="r num">৳ 45,000</td><td class="r num">৳ 45,000</td><td class="r num">—</td></tr>
<tr><td>Salary</td><td>Counter staff</td><td class="r num">৳ 28,000</td><td class="r num">৳ 26,000</td><td class="r num dn">▲ 7.7%</td></tr>
<tr><td>Utilities</td><td>Electricity</td><td class="r num">৳ 9,840</td><td class="r num">৳ 11,200</td><td class="r num up">▼ 12.1%</td></tr>
<tr><td>Transport</td><td>Freight in</td><td class="r num">৳ 6,400</td><td class="r num">৳ 4,900</td><td class="r num dn">▲ 30.6%</td></tr>
<tr><td>Card terminal fees</td><td>—</td><td class="r num">৳ 1,400</td><td class="r num">৳ 1,180</td><td class="r num dn">▲ 18.6%</td></tr>
</tbody><tfoot><tr><td colspan="2">Total</td><td class="r num">৳ 90,640</td><td class="r num">৳ 88,280</td><td class="r num dn">▲ 2.7%</td></tr></tfoot></table>
</div>
</div></div>'''
open('p08-profitloss.html','w').write(page("Profit / Loss", PL))

# ---------- 09 Register report ----------
OPEN_CHIP = '<span class="chip ok">Open</span>'

def rr(sess,user,loc,op,cl,cash,card,bk,ng,cr,tot):
    return (f'<tr><td><b>{sess}</b></td><td>{user}</td><td>{loc}</td><td>{op}</td><td>{cl}</td>'
            f'<td class="r num">{cash}</td><td class="r num">{card}</td><td class="r num">{bk}</td>'
            f'<td class="r num">{ng}</td><td class="r num">{cr}</td><td class="r num"><b>{tot}</b></td></tr>')

REG = f'''{nav(reg=1)}<div class="main">{TOP}<div class="body">
<h1>Register report</h1><div class="crumb">One row per till session · transaction counts in brackets · 01-08-2026 to 26-08-2026</div>
<div class="card">
<div class="filt"><div class="fh">▾ Filters</div><div class="fg">
  <div><label>Location</label><select><option>All locations</option></select></div>
  <div><label>Register</label><select><option>All registers</option><option>R1</option></select></div>
  <div><label>Cashier</label><select><option>All users</option></select></div>
  <div><label>Status</label><select><option>All</option><option>Open</option><option>Closed</option></select></div>
</div></div>
<table>
<thead><tr><th>Shift</th><th>Cashier</th><th>Location</th><th>Opened</th><th>Closed</th><th class="r">Cash</th><th class="r">Card</th><th class="r">bKash</th><th class="r">Nagad</th><th class="r">বাকি</th><th class="r">Total</th></tr></thead>
<tbody>
{rr("R1 · 42","Rehana","Main","26-08 09:04",OPEN_CHIP,"48,320 (96)","12,450 (14)","18,900 (28)","3,200 (6)","6,400 (3)","৳ 89,270")}
{rr("R1 · 41","Monir","Main","25-08 09:00","25-08 21:12","51,180 (104)","9,900 (11)","14,300 (22)","1,800 (4)","4,200 (2)","৳ 81,380")}
{rr("R1 · 40","Rehana","Main","24-08 09:06","24-08 21:04","44,960 (91)","11,240 (13)","16,750 (25)","2,400 (5)","0 (0)","৳ 75,350")}
{rr("R2 · 12","Salma","Kazipara","24-08 10:00","24-08 20:30","22,410 (48)","4,100 (6)","7,900 (12)","900 (2)","1,200 (1)","৳ 36,510")}
{rr("R1 · 39","Monir","Main","23-08 09:02","23-08 21:00","39,870 (84)","8,600 (10)","12,400 (19)","1,500 (3)","2,800 (2)","৳ 65,170")}
</tbody>
<tfoot><tr><td colspan="5">Totals — 5 sessions</td><td class="r num">206,740</td><td class="r num">46,290</td><td class="r num">70,250</td><td class="r num">9,800</td><td class="r num">14,600</td><td class="r num">৳ 347,680</td></tr></tfoot>
</table>
<div class="note">A session's own X-report reconciles opening float + cash sale − refund − expense against the counted drawer. Variance is stored on close and shown here when non-zero.</div>
</div>
<div class="two">
<div class="card"><div class="ch"><h2>Drawer variance by session</h2></div>
<table><thead><tr><th>Shift</th><th class="r">Expected</th><th class="r">Counted</th><th class="r">Variance</th></tr></thead><tbody>
<tr><td>R1 · 41</td><td class="r num">৳ 51,180</td><td class="r num">৳ 51,180</td><td class="r"><span class="chip ok">৳ 0</span></td></tr>
<tr><td>R1 · 40</td><td class="r num">৳ 44,960</td><td class="r num">৳ 44,910</td><td class="r"><span class="chip wa">−৳ 50</span></td></tr>
<tr><td>R2 · 12</td><td class="r num">৳ 22,410</td><td class="r num">৳ 22,410</td><td class="r"><span class="chip ok">৳ 0</span></td></tr>
<tr><td>R1 · 39</td><td class="r num">৳ 39,870</td><td class="r num">৳ 40,070</td><td class="r"><span class="chip wa">+৳ 200</span></td></tr>
</tbody></table></div>
<div class="card"><div class="ch"><h2>Activity log</h2><span class="act">Full log</span></div>
<table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th></tr></thead><tbody>
<tr><td>14:12</td><td>Rehana</td><td><span class="chip inf">Sale added</span></td><td>R1-000042-0147</td></tr>
<tr><td>13:44</td><td>Monir</td><td><span class="chip wa">Discount applied</span></td><td>R1-000042-0145 · ৳ 20</td></tr>
<tr><td>12:58</td><td>Rehana</td><td><span class="chip bad">Sell return</span></td><td>R1-000042-0143</td></tr>
<tr><td>11:20</td><td>Admin</td><td><span class="chip mut">Price updated</span></td><td>Soyabin Oil 1L</td></tr>
<tr><td>09:04</td><td>Rehana</td><td><span class="chip ok">Register opened</span></td><td>R1 · float ৳ 2,000</td></tr>
</tbody></table></div>
</div>
</div></div>'''
open('p09-register-report.html','w').write(page("Register report", REG))
print("p08, p09 written")

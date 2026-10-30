<x-layouts.app heading="Quick guide" subheading="From artwork review to finished print in five steps">
<section class="guide-intro"><div><span class="eyebrow">START HERE</span><h2>A clear path from order to print</h2><p>Review the artwork first. Create a batch when it is ready. Sign before handing it to Edge Print, and confirm completion only after the physical print finishes.</p></div><a class="primary" href="{{route('production')}}">Review artwork →</a></section>
<div class="quick-guide-steps">
@foreach([
['01','Review artwork','Compare the Shopify image with the Drive artwork, including front and back. Choose Approve or Reject. Select multiple products to review them in bulk.','Artwork verification','production'],
['02','Check ready orders','Ready for Print contains approved items that have not entered a batch. Check sets, artwork files and material totals, then create the print batch.','Ready for Print','queue'],
['03','Track the batch','Open Print batches and choose View batch. The progress row shows its current stage. Orders already in a batch will not appear as new ready orders after sync.','Print batches','batches'],
['04','Review and sign handoff','When artwork is prepared, open the batch and choose Review and sign handoff. Enter your name, check the review box, type HANDOFF and accept the final confirmation.','Open batches','batches'],
['05','Print and confirm','Keep Auto Print OFF. Review the jobs and quantities in Edge Print, then start physical printing. Once the whole batch is finished, sign the PRINTED confirmation in the app.','Track completion','batches']
] as $step)
<article class="quick-guide-step"><span class="guide-step-number">{{$step[0]}}</span><div><h3>{{$step[1]}}</h3><p>{{$step[2]}}</p><a href="{{route($step[4])}}">{{$step[3]}} →</a></div></article>
@endforeach
</div>
<section class="panel guide-status"><div class="panel-head"><div><h2>What the statuses mean</h2><p>Use the status cards to filter orders. You can combine them with a design type.</p></div></div><dl>
<div><dt>Pending</dt><dd>Artwork still needs review.</dd></div><div><dt>Approved</dt><dd>Artwork accepted and awaiting a print batch.</dd></div><div><dt>Rejected</dt><dd>Artwork marked wrong; resolve it before printing.</dd></div><div><dt>In print</dt><dd>In a batch; check the stage for preparation or Edge Print delivery.</dd></div><div><dt>Completed</dt><dd>An operator confirmed that physical printing finished.</dd></div><div><dt>Needs attention</dt><dd>Processing failed. Inspect the batch and RIP queue before retrying.</dd></div>
</dl></section>
<section class="panel guide-notes"><h2>Useful to know</h2><p><strong>Sync safely.</strong> Refresh backlog adds new items and retains saved decisions. Batched and completed items are not added to the print queue again.</p><p><strong>Material totals.</strong> Printed fabric and plain backing are separate. Total fabric includes both, using the configured allowance per side per set.</p><p><strong>Human confirmation.</strong> Handoff and completion record your account, entered name, counts and time. Sending files to Edge Print does not mean they have physically printed.</p><p><strong>More than one set.</strong> Automatic hot-folder delivery for quantities above one is blocked until the Edge Print quantity workflow is validated. Preparation still retains the order quantities.</p></section>
</x-layouts.app>

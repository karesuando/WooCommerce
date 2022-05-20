jQuery(document).ready(function($) {
	$("button.button-secondary").click(function() {
		let machineId = $("input.machine_id").val();
		let machineKey = $("input.machine_key").val();
		let integratorId = $("input.integrator_id").val();

		if (machineId === '' || machineKey === '' || integratorId === '')
		{
			alert("Please enter all API-keys");
			return;
		}
		let ajax_data = {
			action: 'get_inventoryitem',
			machineId: machineId,
			machineKey: machineKey,
			integratorId: integratorId
		};
		$.ajax({
			type: 'POST',
			data: ajax_data,
			url: wp_admin.ajaxurl,
			dataType: 'json',
			success: function(response) {
				$("#status").val(response.status);
			},
			error: function(xhr, status) {
				$("#status").val('AJAX error: ' + xhr.status);
			}
		})
	})
});

/*
jQuery(document).ready(function($) {
    // REST API requests sent from client doesn't work for some reason
	$("button.button-secondary").click(function() {
		let dinkassaRequest = new XMLHttpRequest();
		dinkassaRequest.open('GET', 'https://www.dinkassa.se/api/inventoryitem?fetch=1', true);
		dinkassaRequest.setRequestHeader('machineId', $("input.machine_id").val());
		dinkassaRequest.setRequestHeader('machineKey', $("input.machine_key").val());
		dinkassaRequest.setRequestHeader('integratorId', $("input.integrator_id").val());
		dinkassaRequest.onload = function()
		{
			$("#status").val(dinkassaRequest.status);
		}
		dinkassaRequest.send();
	})
});
*/

// JQuery scripts to detect product data that has been modified. The hidden inputs
// _modified_custom_fields and _modified_builtin_fields are bit fields. A value == 0
// indicates unmodified data. Any other value means that some of the input fields have
// been modified.
jQuery(document).ready(function($) {
	// Existing product
	let modified_custom_bitfield = 0;
	let modified_builtin_bitfield = 0;
	let regular_price_input = $("#_regular_price");
	let custom_field_inputs = $("*[id^='_custom_pf_']");
	let synchronize_description = $("#_synchronize_description").val();
	let product_visibility_radiobutton = $("input[name='_visibility']");
	let current_regular_price = regular_price_input.val();
	let current_visibility = $("input[name='_visibility']:checked").attr('id');
	let current_visibility_on_sales_menu = current_visibility === '_visibility_visible'
										|| current_visibility === '_visibility_catalog';
	// Create an associative map between HTML input IDs
	// and the current value of custom product fields.
	const custom_field = {};
	custom_field_inputs.each(function (index, inputfield) {
		custom_field[inputfield.id] = {
			currentValue: inputfield.value,
			bitMask: 0x1 << index
		}
	});
	$("#_modified_custom_fields").val(null);
	$("#_modified_builtin_fields").val(null);
	if ($("#_synchronize_prices").val()) {
		regular_price_input.on('change', function () {
			const regular_price = this.value;
			if (current_regular_price !== regular_price)
				modified_builtin_bitfield |= 0x1;
			else
				modified_builtin_bitfield &= ~0x1;
		})
	}
	product_visibility_radiobutton.on('change', function () {
		const visibility = this.id;
		const visibility_on_sales_menu = visibility === '_visibility_visible'
			                          || visibility === '_visibility_catalog';
		if (current_visibility_on_sales_menu ^ visibility_on_sales_menu)
			modified_builtin_bitfield |= 0x2;
		else
			modified_builtin_bitfield &= ~0x2;
	});
	custom_field_inputs.on('change', function () {
		const id = this.id;
		const inputValue = this.value;
		if (id === '_custom_pf_description') {
			if (synchronize_description)
				$("#title").val(inputValue);
			else
				return;
		}
		const {bitMask, currentValue} = custom_field[id];
		if (currentValue !== inputValue)
			modified_custom_bitfield |= bitMask;
		else
			modified_custom_bitfield &= ~bitMask;
	});
	if (synchronize_description) {
		const id = '_custom_pf_description';
		const {bitMask, currentTitle} = custom_field[id];
		$("#title").on('change', function () {
			const title = this.value;
			$("#_custom_pf_description").val(title);
			if (currentTitle !== title)
				modified_custom_bitfield |= bitMask;
			else
				modified_custom_bitfield &= ~bitMask;
		})
	}
	$("#post").submit(function () {
		$("#_modified_custom_fields").val(modified_custom_bitfield);
		$("#_modified_builtin_fields").val(modified_builtin_bitfield);
	})
});

jQuery(document).ready(function($) {
	let sync_checkbox = $("input.synch_checkbox");
	let value = sync_checkbox.is(':checked');
	set_required_attribute($, value);
	sync_checkbox.on('click', function() {
		value = sync_checkbox.is(':checked');
		set_required_attribute($, value);
	});
});

function set_required_attribute($, value)
{
	$("input.machine_id").attr("required", value);
	$("input.machine_key").attr("required", value);
	$("input.integrator_id").attr("required", value);
}

jQuery(document).ready(function($) {
	let category_input = $("#_custom_pf_categoryname");
	$("input[id^='in-product_cat-']").on('change', function() {
		let selected_category = $(this).parent().text().trim();
		if (selected_category.localeCompare('Uncategorized') === 0)
			$(this).prop('checked', true); // Don't uncheck 'Uncategorized'
		else {
			let current_cat = category_input.val().trim();
			$(this).parent().css('font-weight', 'normal');
			if (selected_category.localeCompare(current_cat) === 0) {
				// Main category unchecked. Check 'Uncategorized' checkbox
				// and set main category option to blank
				select_uncategorized_category($);
			}
		}
	})
})

jQuery(document).ready(function($) {
	let category_input = $("#_custom_pf_categoryname");
	let category_id_input = $("#_custom_pf_current_cat_term_id");
	if (category_id_input.val() === "")
		select_uncategorized_category($); // New product. Set main category to 'Uncategorized'
	else {
		let category_id = category_id_input.val();
		let selector = 'input#in-product_cat-' + category_id;
		$(selector).parent().css('font-weight', 'bold');
	}
	category_input.on('change', function(event) {
		let selected_category = $(this).val().trim();
		let category_id = category_id_input.val();
		let selector = 'input#in-product_cat-' + category_id;
		$(selector).prop('checked', false);
		$(selector).parent().css('font-weight', 'normal');
		selector = "label.selectit:contains(" + selected_category + ") > input";
		$(selector).prop('checked', true);
		$(selector).parent().css('font-weight', 'bold');
		if (selected_category.localeCompare('Uncategorized') === 0)
		{
			category_input.prop("selectedIndex", -1);
		}
		category_id = $(selector).val();
		category_id_input.val(category_id)
	})
})

function select_uncategorized_category($)
{
	$("#_custom_pf_categoryname").prop("selectedIndex", -1);
	let selector = "label.selectit:contains(Uncategorized) > input";
	$(selector).prop('checked', true);
	$(selector).parent().css('font-weight', 'bold');
	let category_term_id = $(selector).val();
	$("#_custom_pf_current_cat_term_id").val(category_term_id);
}

jQuery(document).ready(function($) {
	$("#isbn_form").submit(function (event) {
		alert("Handler for .submit() called.");
	})
})
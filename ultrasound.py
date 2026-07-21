from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
import os

def get_user_inputs():
    print("=== Enter Ultrasound Form Details ===")
    data = {}
    
    # 1. Header Fields
    data['name'] = input("Patient Name: ")
    data['age'] = input("Age: ")
    data['date'] = input("Date (e.g., 21-07-2026): ")
    data['fetus_status'] = input("Fetus Present in (e.g., intrauterine / extrauterine): ")

    # 2. Measurements
    print("\n--- Fetal Measurements ---")
    data['bpd_meas'] = input("Biparietal Diameter Measurement (mm): ")
    data['bpd_age'] = input("Biparietal Diameter Gest. Age (Wks): ")
    
    data['femur_meas'] = input("Femur Length Measurement (mm): ")
    data['femur_age'] = input("Femur Length Gest. Age (Wks): ")
    
    data['ac_meas'] = input("Abdominal Circumference Measurement (mm): ")
    data['ac_age'] = input("Abdominal Circumference Gest. Age (Wks): ")
    
    data['crl_meas'] = input("Crown Rump Length Measurement (mm): ")
    data['crl_age'] = input("Crown Rump Length Gest. Age (Wks): ")

    # 3. Clinical Details
    print("\n--- Clinical Details ---")
    data['gest_age'] = input("Gestational Age is (Wks): ")
    data['edd'] = input("EDD: ")
    data['heart_motion'] = input("Fetal Heart Motion: ")
    data['placenta'] = input("Placenta is in: ")
    data['placenta_grade'] = input("Position with grade: ")
    data['amniotic_fluid'] = input("Amniotic Fluid Volume: ")
    data['presentation'] = input("Lie / Presentation: ")

    # 4. Anatomy Checkmarks (Yes/No)
    print("\n--- Anatomy Checkmarks (Enter 'yes' or 'no') ---")
    anatomy_keys = ['lt_ventricular', 'bpd_level', 'feral_stomach', 'kidneys', 'bladder', 'spine']
    data['anatomy_status'] = []
    for key in anatomy_keys:
        status = input(f"{key.replace('_', ' ').title()} - Yes/No: ").strip().lower()
        data['anatomy_status'].append(status)

    # 5. Biophysical Profile
    data['bpp'] = input("\nBiophysical Profile (Poor / Normal / Good): ").strip()

    # 6. Conclusion
    print("\n--- Conclusion ---")
    data['conclusion_line1'] = input("Conclusion Line 1: ")
    data['conclusion_line2'] = input("Conclusion Line 2: ")

    return data

def generate_pdf(filename, data):
    # A4 dimensions in points (1 mm = 2.83465 points)
    mm = 2.83465
    page_height = 841.89  # A4 height in points
    
    c = canvas.Canvas(filename, pagesize=A4)
    
    def write_field(text, grid_x, grid_y):
        if not text:
            return
        x_pts = grid_x * 5 * mm
        # Convert top-left negative Y grid to standard bottom-up PDF points
        y_pts = page_height + (grid_y * 5 * mm)
        c.drawString(x_pts, y_pts, str(text))

    c.setFont("Helvetica", 10)

    # 1. Header Fields Mapping
    write_field(data.get('name', ''), 7, -12)
    write_field(data.get('age', ''), 31, -12)
    write_field(data.get('date', ''), 36, -12)
    write_field(data.get('fetus_status', ''), 16, -14)

    # 2. Measurements Mapping
    write_field(data.get('bpd_meas', ''), 22, -18)
    write_field(data.get('bpd_age', ''), 36, -18)
    
    write_field(data.get('femur_meas', ''), 22, -20)
    write_field(data.get('femur_age', ''), 36, -20)
    
    write_field(data.get('ac_meas', ''), 22, -22)
    write_field(data.get('ac_age', ''), 36, -22)
    
    write_field(data.get('crl_meas', ''), 22, -24)
    write_field(data.get('crl_age', ''), 36, -24)

    # 3. Clinical Details Mapping
    write_field(data.get('gest_age', ''), 10, -27)
    write_field(data.get('edd', ''), 35, -27)
    write_field(data.get('heart_motion', ''), 11, -29)
    write_field(data.get('placenta', ''), 11, -31)
    write_field(data.get('placenta_grade', ''), 31, -31.5)
    write_field(data.get('amniotic_fluid', ''), 16, -33.5)
    write_field(data.get('presentation', ''), 37, -33.5)

    # 4. Anatomy Checkmarks Mapping ('X')
    anatomy_rows = [-38, -39 , -40, -41.5, -43, -44]
    for row_y, status in zip(anatomy_rows, data.get('anatomy_status', [])):
        if status == 'yes':
            c.drawString(22.5 * 5 * mm, page_height + (row_y * 5 * mm), "X")
        elif status == 'no':
            c.drawString(31 * 5 * mm, page_height + (row_y * 5 * mm), "X")

    # 5. Biophysical Profile Mapping
    bpp = data.get('bpp', '').capitalize()
    if bpp == 'Poor':
        c.drawString(14 * 5 * mm, page_height + (-46 * 5 * mm), "X")
    elif bpp == 'Normal':
        c.drawString(23.5 * 5 * mm, page_height + (-46 * 5 * mm), "X")
    elif bpp == 'Good':
        c.drawString(35 * 5 * mm, page_height + (-46 * 5 * mm), "X")

    # 6. Conclusion Mapping
    write_field(data.get('conclusion_line1', ''), 10, -48)
    write_field(data.get('conclusion_line2', ''), 5, -50)

    c.save()
    print(f"\n[Success] Generated PDF: {filename}")

if __name__ == "__main__":
    user_data = get_user_inputs()
    output_filename = "ultrasound_output.pdf"
    generate_pdf(output_filename, user_data)